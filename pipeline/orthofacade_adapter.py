"""將已凍結官方建物送入固定版本 orthofacade；不使用上游示範 Token。"""
from __future__ import annotations

import contextlib
import hashlib
import io
import json
import math
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import time
import zlib
from pathlib import Path

import numpy as np
import requests
from PIL import Image
from shapely.affinity import affine_transform
from shapely.geometry import Point, shape

ENGINE_COMMIT = 'a24e4ca349a1ceecec5c64517e62b6828f4aebac'
SOURCE_KEY = 'new-taipei-8418'
ROOT = Path(__file__).resolve().parents[1]
UPSTREAM = ROOT / 'vendor' / 'orthofacade'
META_FIELDS = ('id,is_pano,captured_at,computed_geometry,computed_rotation,computed_altitude,'
               'computed_compass_angle,compass_angle,atomic_scale,sequence,width,height,quality_score,sfm_cluster')


def write_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False), encoding='utf-8')
    temporary.replace(path)


def redact_message(message, secrets=()):
    result = str(message)
    for secret in secrets:
        if secret:
            result = result.replace(secret, '[redacted]')
    result = re.sub(r'MLY\|[^\s\"\'<>]+', '[redacted-token]', result)
    return re.sub(r'(https?://[^\s\"\'<>?]+)\?[^\s\"\'<>]+', r'\1?[redacted-query]', result)


def safe_metadata(row):
    allowed = set(META_FIELDS.split(',')) | {'creator'}
    clean = {key: value for key, value in row.items() if key in allowed and key != 'sfm_cluster'}
    cluster = row.get('sfm_cluster') or {}
    clean['sfm_cluster'] = {'id': str(cluster['id'])} if cluster.get('id') else {}
    return clean


def validate_feature(feature, require_height=True):
    properties = feature.get('properties') or {}
    if feature.get('type') != 'Feature' or not properties.get('build_id'):
        raise ValueError('官方建物必須有 Feature 與 build_id。')
    if properties.get('source_key') != SOURCE_KEY or not re.fullmatch(r'[a-f0-9]{64}', properties.get('source_hash', '')):
        raise ValueError('必須沿用 PHP 凍結的 source_key/source_hash。')
    height = properties.get('height')
    if require_height and (not isinstance(height, (int, float)) or not math.isfinite(height) or height <= 0):
        raise ValueError('試轉目標必須有官方有效建物高度。')
    geometry = feature.get('geometry') or {}
    if geometry.get('type') not in ('Polygon', 'MultiPolygon'):
        raise ValueError('僅接受官方 Polygon/MultiPolygon。')
    for polygon in polygon_parts(feature):
        for ring in polygon:
            for position in ring:
                if len(position) != 2 or not all(isinstance(value, (int, float)) and math.isfinite(value) for value in position):
                    raise ValueError('官方輪廓座標必須是有限 WGS84 數值。')
    try:
        geom = shape(geometry)
        coordinates = geom.bounds
        if geom.is_empty or not geom.is_valid or len(coordinates) != 4 or not all(map(math.isfinite, coordinates)):
            raise ValueError('官方建物輪廓不合法。')
        if not (-180 <= coordinates[0] <= coordinates[2] <= 180 and -90 <= coordinates[1] <= coordinates[3] <= 90):
            raise ValueError('官方建物必須使用 WGS84。')
    except (TypeError, KeyError) as error:
        raise ValueError('官方建物輪廓無法讀取。') from error
    return feature


def load_frozen_features(database, job_id=None):
    con = sqlite3.connect(Path(database).resolve().as_uri() + '?mode=ro', uri=True)
    con.row_factory = sqlite3.Row
    try:
        con.execute('PRAGMA query_only=ON')
        if not job_id:
            row = con.execute('SELECT id FROM jobs ORDER BY created_at DESC LIMIT 1').fetchone()
            if row is None:
                raise ValueError('沒有可試轉的已凍結任務。')
            job_id = row['id']
        rows = con.execute('SELECT build_id,source_key,source_hash,recipe_key,feature_json FROM job_items WHERE job_id=? ORDER BY build_id', (job_id,)).fetchall()
    finally:
        con.close()
    features = []
    for row in rows:
        feature = json.loads(row['feature_json'])
        properties = feature.get('properties') or {}
        if any(properties.get(key) != row[key] for key in ('build_id', 'source_key', 'source_hash')):
            raise ValueError('凍結建物識別或 source_hash 不一致。')
        feature['properties']['recipe_key'] = row['recipe_key']
        features.append(feature)
    return features


def pilot_directory(root, run):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_-]{0,63}', run or ''):
        raise ValueError('run 必須是安全的 ASCII 名稱。')
    path = (Path(root) / 'db' / 'pilots' / run).resolve()
    if not str(path).isascii():
        raise ValueError('OpenCV 工作路徑必須為 ASCII，請改用 ASCII workspace。')
    return path


def load_discovery(path, bbox, client):
    path = Path(path)
    if path.is_file():
        data = json.loads(path.read_text(encoding='utf-8'))
        if list(data.get('bbox') or []) != list(bbox):
            raise ValueError('此 run 已凍結不同 bbox，請使用新的 run 名稱。')
        return data['images']
    rows = client.images(bbox)
    write_json(path, {'bbox': list(bbox), 'images': rows})
    return rows


def stable_id(text):
    return int(hashlib.sha256(text.encode('utf-8')).hexdigest()[:12], 16) + 1


def polygon_parts(feature):
    geometry = feature['geometry']
    return [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']


def node_id(build_id, polygon, ring, vertex):
    return stable_id(f'{SOURCE_KEY}:{build_id}:node:{polygon}:{ring}:{vertex}')


def official_cache(features, target_build_id):
    elements, mapping = [], {}
    for feature in sorted(features, key=lambda value: value['properties']['build_id']):
        properties = feature['properties']
        build_id = properties['build_id']
        validate_feature(feature, require_height=build_id == target_build_id)
        polygons = polygon_parts(feature)
        tags = {'building': 'yes', '_build_id': build_id, '_source_key': SOURCE_KEY, '_target': build_id == target_build_id}
        height = properties.get('height')
        if isinstance(height, (int, float)) and math.isfinite(height) and height > 0:
            tags['height'] = str(height)
        relation = len(polygons) > 1 or len(polygons[0]) > 1
        identifier = stable_id(f'{SOURCE_KEY}:{build_id}:building')
        members, keys = [], []
        for polygon_index, rings in enumerate(polygons):
            for ring_index, ring in enumerate(rings):
                raw = ring[:-1] if ring[0] == ring[-1] else ring
                ids = [node_id(build_id, polygon_index, ring_index, index) for index in range(len(raw))]
                for identifier_node, position in zip(ids, raw):
                    elements.append({'type': 'node', 'id': identifier_node, 'lon': position[0], 'lat': position[1]})
                way = stable_id(f'{SOURCE_KEY}:{build_id}:way:{polygon_index}:{ring_index}') if relation else identifier
                elements.append({'type': 'way', 'id': way, 'nodes': ids + [ids[0]], 'tags': {} if relation else tags})
                members.append({'type': 'way', 'ref': way, 'role': 'outer' if ring_index == 0 else 'inner'})
            keys.append(('r' if relation else 'w') + str(identifier) + (f'_{polygon_index}' if relation and polygon_index else ''))
        if relation:
            elements.append({'type': 'relation', 'id': identifier, 'members': members, 'tags': {**tags, 'type': 'multipolygon'}})
        mapping[build_id] = keys
    if target_build_id not in mapping:
        raise ValueError('目標建物不在官方清單。')
    return {'elements': elements, 'source': SOURCE_KEY, 'synthetic_identifiers': True}, mapping


class MapillaryClient:
    def __init__(self, token, session=None):
        self.token = token.strip()
        if not self.token or '\n' in self.token or '\r' in self.token:
            raise ValueError('必須提供自己的單行 Mapillary Client Token。')
        self.session = session or requests.Session()

    def _json(self, endpoint, params):
        for attempt in range(3):
            try:
                response = self.session.get('https://graph.mapillary.com/' + endpoint, params=params,
                                            headers={'Authorization': 'OAuth ' + self.token, 'User-Agent': '3wa-GeoTexture-pilot/1.0'}, timeout=45)
                if response.status_code in (429, 500, 502, 503) and attempt < 2:
                    time.sleep(2 ** (attempt + 1))
                    continue
                if response.status_code != 200:
                    raise RuntimeError(f'Mapillary HTTP {response.status_code}（未記錄憑證或回應 URL）。')
                return response.json()
            except requests.RequestException as error:
                if attempt == 2:
                    raise RuntimeError('Mapillary 網路請求失敗：' + type(error).__name__) from None
                time.sleep(2 ** (attempt + 1))
        raise RuntimeError('Mapillary 請求未完成。')

    def images(self, bbox):
        body = self._json('images', {'bbox': ','.join(map(str, bbox)), 'is_pano': 'true', 'limit': 100, 'fields': META_FIELDS})
        if (body.get('paging') or {}).get('next'):
            raise ValueError('街景中繼資料仍有分頁，請縮小 pilot bbox。')
        rows = body.get('data')
        if not isinstance(rows, list):
            raise ValueError('Mapillary 影像清單不完整。')
        return [safe_metadata(row) for row in rows]

    def details(self, image_id):
        return self._json(str(image_id), {'fields': META_FIELDS + ',thumb_original_url,thumb_2048_url,creator'})

    def download(self, url, maximum=128 * 1024 * 1024):
        if not isinstance(url, str) or not url.startswith('https://'):
            raise ValueError('Mapillary 未提供有效 HTTPS 下載位置。')
        try:
            # 簽名 CDN 不接收 Graph Token；例外也不能帶出簽名 URL。
            response = self.session.get(url, headers={'User-Agent': '3wa-GeoTexture-pilot/1.0'}, timeout=90, stream=True)
            if response.status_code != 200:
                raise RuntimeError(f'Mapillary CDN HTTP {response.status_code}。')
            output = bytearray()
            for chunk in response.iter_content(1024 * 1024):
                output.extend(chunk)
                if len(output) > maximum:
                    raise ValueError('單筆影像／SfM 超過 pilot 下載上限。')
            return bytes(output)
        except requests.RequestException as error:
            raise RuntimeError('Mapillary CDN 網路失敗：' + type(error).__name__) from None


def metric_geometry(feature, origin=None):
    geom = shape(feature['geometry'])
    origin = origin or (geom.centroid.x, geom.centroid.y)
    kx = 111195.0 * math.cos(math.radians(origin[1]))
    return affine_transform(geom, [kx, 0, 0, 111195.0, -origin[0]*kx, -origin[1]*111195.0]), origin


def rank_candidates(features, metadata):
    ranked = []
    for feature in features:
        try:
            validate_feature(feature)
            geom, origin = metric_geometry(feature)
            if feature['geometry']['type'] != 'Polygon' or len(feature['geometry']['coordinates']) != 1:
                continue
            vertices = len(feature['geometry']['coordinates'][0]) - 1
            if vertices > 16 or geom.area < 20 or geom.area > 1200:
                continue
            distances = []
            for row in metadata:
                coordinates = (row.get('computed_geometry') or {}).get('coordinates', [])
                if len(coordinates) < 2 or not row.get('computed_rotation'):
                    continue
                point = Point((coordinates[0] - origin[0]) * 111195 * math.cos(math.radians(origin[1])), (coordinates[1] - origin[1]) * 111195)
                distance = geom.distance(point)
                if 4 <= distance <= 60:
                    distances.append(distance)
            if distances:
                score = min(distances) + .7 * max(0, vertices - 4) + .2 * abs(feature['properties']['height'] - 12) - min(3, sum(d < 35 for d in distances)) * 2
                ranked.append({'build_id': feature['properties']['build_id'], 'distance_m': min(distances), 'vertices': vertices,
                               'area_m2': geom.area, 'height': feature['properties']['height'], 'score': score, 'feature': feature})
        except (ValueError, TypeError):
            continue
    return sorted(ranked, key=lambda row: (row['score'], row['build_id']))


def select_panos(feature, metadata, maximum=6):
    geom, origin = metric_geometry(feature)
    candidates = []
    for row in metadata:
        coordinates = (row.get('computed_geometry') or {}).get('coordinates', [])
        if len(coordinates) < 2 or not row.get('computed_rotation') or not (row.get('sfm_cluster') or {}).get('id'):
            continue
        point = Point((coordinates[0] - origin[0]) * 111195 * math.cos(math.radians(origin[1])), (coordinates[1] - origin[1]) * 111195)
        distance = geom.distance(point)
        if 4 <= distance <= 65:
            candidates.append((distance, row))
    return [row for _, row in sorted(candidates, key=lambda pair: pair[0])[:maximum]]


def neighbours_from_php(source_database, bbox, php=None):
    php = php or shutil.which('php')
    if not php:
        raise ValueError('找不到 PHP CLI，無法沿用 BuildingSource 的來源 hash。')
    code = 'require $argv[1]; $s=new \\GeoTexture\\BuildingSource($argv[2]); echo json_encode($s->query(json_decode($argv[3],true),10000),JSON_THROW_ON_ERROR);'
    process = subprocess.run([php, '-d', 'log_errors=0', '-r', code, str(ROOT / 'src' / 'BuildingSource.php'), str(source_database), json.dumps(bbox)],
                             capture_output=True, text=True, encoding='utf-8', timeout=30, cwd=ROOT)
    if process.returncode:
        raise RuntimeError('PHP 官方建物來源查詢失敗：' + process.stderr[-1000:])
    result = json.loads(process.stdout)
    if result['meta']['truncated']:
        raise ValueError('鄰近官方建物查詢被截斷，請縮小 pilot 區域。')
    return result['features']


def cache_images(client, selected, work):
    cache = Path(work) / 'cache'
    for folder in ('panos', 'thumbs', 'sfm'):
        (cache / folder).mkdir(parents=True, exist_ok=True)
    frozen = []
    for row in selected:
        details = client.details(row['id'])
        clean = safe_metadata(details)
        image_path = cache / 'panos' / (str(row['id']) + '.jpg')
        if not image_path.is_file():
            data = client.download(details.get('thumb_original_url') or details.get('thumb_2048_url'))
            with Image.open(io.BytesIO(data)) as image:
                image.verify()
            image_path.write_bytes(data)
        with Image.open(image_path) as image:
            clean['width'], clean['height'] = image.size
            thumb_path = cache / 'thumbs' / (str(row['id']) + '_2048.jpg')
            image = image.convert('RGB')
            image.thumbnail((2048, 1024), Image.Resampling.LANCZOS)
            image.save(thumb_path, quality=92)
        cluster = details.get('sfm_cluster') or {}
        cluster_path = cache / 'sfm' / (str(cluster.get('id', 'missing')) + '.json')
        if not cluster.get('id') or not cluster.get('url'):
            raise ValueError('選中全景缺少可下載的 SfM 點雲。')
        if not cluster_path.is_file():
            raw = client.download(cluster['url'])
            try:
                raw = zlib.decompress(raw)
            except zlib.error:
                pass
            json.loads(raw)
            cluster_path.write_bytes(raw)
        clean['image_sha256'] = hashlib.sha256(image_path.read_bytes()).hexdigest()
        clean['sfm_sha256'] = hashlib.sha256(cluster_path.read_bytes()).hexdigest()
        frozen.append(clean)
        print(f"cached pano {row['id']} image={clean['width']}x{clean['height']} sfm={cluster['id']}", flush=True)
    return frozen


def source_edges(feature, record):
    build_id = feature['properties']['build_id']
    index = {}
    for polygon_index, rings in enumerate(polygon_parts(feature)):
        for ring_index, ring in enumerate(rings):
            for edge in range(len(ring) - 1):
                a = node_id(build_id, polygon_index, ring_index, edge)
                b = node_id(build_id, polygon_index, ring_index, (edge + 1) % (len(ring) - 1))
                value = {'polygon': polygon_index, 'ring': ring_index, 'edge': edge, 'a_lonlat': ring[edge], 'b_lonlat': ring[edge + 1]}
                index[a, b] = {**value, 'reversed': False}
                index[b, a] = {**value, 'reversed': True}
    output = []
    for edge in record.get('edges') or []:
        value = index.get((edge.get('node_a'), edge.get('node_b')))
        if value:
            output.append({**value, **{key: edge[key] for key in ('s0', 's1') if key in edge}})
    return output


def make_manifest(feature, records, work, run, imagery, imagery_hash, engine_quality=None):
    validate_feature(feature)
    work = Path(work).resolve()
    walls = []
    for record in records:
        selected_texture = record.get('raw_tex') or record.get('tex')
        texture = work / 'out' / run / 'texture' / 'tex' / str(selected_texture or '')
        valid = False
        pixel_share = 0.0
        if selected_texture and texture.resolve().is_relative_to(work) and texture.is_file():
            try:
                with Image.open(texture) as image:
                    image.load()
                    alpha = image.convert('RGBA').getchannel('A')
                    pixel_share = float(np.asarray(alpha).mean()) / 255.0
                    valid = image.width > 0 and image.height > 0 and pixel_share > 0
            except (OSError, ValueError):
                pass
        engine_observed = float(record.get('observed_share') or 0)
        observed = min(engine_observed, pixel_share)
        flags = list(record.get('flags') or [])
        unmasked_fill = not record.get('raw_tex') and float(record.get('hole_fraction') or 0) > 0
        if unmasked_fill:
            flags.append('OBSERVED_MASK_REQUIRED')
            observed = 0.0
        textured = valid and record.get('tier') in ('A', 'B') and observed > 0 and not record.get('error') and not unmasked_fill
        status = 'textured' if textured else ('low_quality' if valid else 'missing')
        extent = dict(record.get('extent') or {'s_l': 0, 's_r': record.get('length_m', 0)})
        rendered = record.get('render_rect')
        if isinstance(rendered, (list, tuple)) and len(rendered) == 4:
            extent.update(s_l=float(rendered[0]), s_r=float(rendered[1]), h_bot=float(rendered[2]), h_top=float(rendered[3]))
        else:
            # 上游實際取樣高度是整數 rows，不是尚未取整的 sky-height 估計。
            extent.update(h_bot=0.0, h_top=float(record.get('rows') or record.get('height_used_m') or feature['properties']['height']))
        wall = {key: record.get(key) for key in ('key', 'a_lonlat', 'b_lonlat', 'length_m', 'tier', 'confidence', 'flags', 'unknown_share',
                                                'observed_share', 'views', 'edges', 'piece', 'n_pieces', 's_offset', 'plane', 'error', 'hole_fraction', 'fuse_mode')}
        wall.update(height_m=feature['properties']['height'], upstream_height_m=record.get('height_used_m'), extent=extent,
                    tex_path=str(texture.resolve()) if textured else None, status=status, source_edges=source_edges(feature, record),
                    observed_share=observed, engine_observed_share=engine_observed,
                    texture_source='single_view_resample' if record.get('raw_tex') else 'upstream_fused', flags=flags)
        walls.append(wall)
    geom = shape(feature['geometry'])
    attribution = [{'image_id': str(row['id']), 'author': row.get('creator'),
                    'source_url': 'https://www.mapillary.com/app/?pKey=' + str(row['id']), 'license': 'CC-BY-SA-4.0',
                    'changes': '依官方新北建物牆面正射校正、裁切、遮擋遮罩與多視角融合'} for row in imagery]
    return {'version': 1, 'source_key': SOURCE_KEY, 'build_id': feature['properties']['build_id'],
            'source_hash': feature['properties']['source_hash'], 'recipe_key': feature['properties'].get('recipe_key', 'texture-v1'),
            'engine_commit': ENGINE_COMMIT, 'adapter_version': 1, 'feature': feature,
            'origin': {'longitude': geom.centroid.x, 'latitude': geom.centroid.y}, 'walls': walls,
            'attribution': attribution, 'imagery_hash': imagery_hash, 'engine_quality': engine_quality or {}}


class RedactingWriter:
    def __init__(self, path, secrets):
        self.file = Path(path).open('a', encoding='utf-8')
        self.secrets = secrets

    def write(self, text):
        safe = redact_message(text, self.secrets)
        self.file.write(safe)
        sys.__stdout__.write(safe)
        return len(text)

    def flush(self):
        self.file.flush()
        sys.__stdout__.flush()


def run_official(feature, neighbours, imagery, work, token, *, tex_ppb=8, inspection=False):
    work = Path(work)
    if tex_ppb not in (8, 16, 32, 64):
        raise ValueError('真實重取樣解析度只接受 8/16/32/64 px/m。')
    if str(UPSTREAM) not in sys.path:
        sys.path.insert(0, str(UPSTREAM))
    import common
    import geo
    import register
    import export
    actual = subprocess.check_output(['git', '-C', str(UPSTREAM), 'rev-parse', 'HEAD'], text=True).strip()
    if actual != ENGINE_COMMIT:
        raise ValueError('orthofacade 版本與固定 commit 不符。')
    all_features = {row['properties']['build_id']: row for row in neighbours}
    all_features[feature['properties']['build_id']] = feature
    osm, mapping = official_cache(list(all_features.values()), feature['properties']['build_id'])
    keys = set(mapping[feature['properties']['build_id']])
    bounds = shape(feature['geometry']).bounds
    bbox = [bounds[1], bounds[0], bounds[3], bounds[2]]
    write_json(work / 'cache' / 'osm.json', osm)
    write_json(work / 'cache' / 'panos.json', {'bbox': bbox, 'panos': imagery})
    write_json(work / 'source-map.json', {'target': feature['properties']['build_id'], 'source_hash': feature['properties']['source_hash'], 'keys': sorted(keys)})
    paths, params = common.Paths(str(work), 'pilot'), common.Params(tex_ppb=tex_ppb)
    original_parse = geo.parse_overpass
    def parse_official(*args, **kwargs):
        buildings = original_parse(*args, **kwargs)
        for building in buildings:
            building.target = building.key in keys
        return buildings
    log = RedactingWriter(work / 'engine.log', [token])
    try:
        with contextlib.redirect_stdout(log), contextlib.redirect_stderr(log):
            geo.parse_overpass = parse_official
            try:
                geometry = geo.run_geometry(paths, params, tuple(bbox))
            finally:
                geo.parse_overpass = original_parse
            wall_records = common.read_json(paths.geometry('walls'))
            wall_keys = {wall['key'] for wall in wall_records if wall['building_key'] in keys}
            alignment = register.run_align(paths, params, only_walls=wall_keys, workers=1)
            texture = export.run_texture(paths, params, only_walls=wall_keys, only_buildings=keys, workers=1, hires='on')
            write_json(work / 'stage-summary.json', {'geometry': geometry, 'align': alignment, 'texture': texture})
            if inspection:
                import sheet
                summary = sheet.run_sheet(paths, params, only_ways=keys, max_walls=1, workers=1)
                write_json(work / 'inspection-summary.json', summary)
    finally:
        log.flush()
        log.file.close()
    records = []
    for key in sorted(keys):
        path = Path(paths.facade_json(key))
        if path.is_file():
            records.extend(common.read_json(path).get('walls') or [])
    for record in records:
        path = Path(paths.refine_wall(record['key']))
        if path.is_file():
            record['render_rect'] = common.read_json(path).get('rect')
        views = record.get('views') or []
        if record.get('fuse_mode') == 'single' and len(views) == 1:
            # 單視角保留 fusion/inpaint 前的觀測遮罩，未知區域由 GLB 保持白色。
            record['raw_tex'] = Path(paths.tex_view(common.view_key(record['key'], views[0]['pano']))).name
    imagery_hash = hashlib.sha256(json.dumps({'images': sorted(imagery, key=lambda row: row['id']), 'engine': ENGINE_COMMIT},
                                           sort_keys=True, ensure_ascii=False, allow_nan=False).encode('utf-8')).hexdigest()
    warnings = []
    if alignment['registration']['global']:
        warnings.append('GLOBAL_REGISTRATION')
    if not alignment['planes_cloud']:
        warnings.append('NO_CLOUD_PLANE')
    if alignment['single_view']:
        warnings.append('SINGLE_VIEW')
    if geometry['ground_sources'].get('default'):
        warnings.append('DEFAULT_CAMERA_HEIGHT')
    if any('UNVERIFIED' in record.get('flags', []) for record in records):
        warnings.append('UNVERIFIED')
    quality = {'registration': alignment['registration'], 'ground_sources': geometry['ground_sources'],
               'plane_sources': alignment['plane_sources'], 'warnings': warnings}
    pilot = make_manifest(feature, records, work, 'pilot', imagery, imagery_hash, engine_quality=quality)
    pilot['parameters'] = params.to_json()
    pilot['verification_status'] = 'trial_unverified'
    write_json(work / 'pilot.json', pilot)
    return pilot
