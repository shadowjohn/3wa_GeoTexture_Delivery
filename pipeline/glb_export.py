"""將單棟 orthofacade pilot 契約轉成完整輪廓、內嵌實圖的 glTF 2 GLB。

本地座標沿用上游球面 Frame：X 東、Y 上、Z 南，單位公尺。
缺面及原圖 alpha=0 的未知區保持不透明白色；blocks 分類影像不應傳入此介面。
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import re
import struct
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

import numpy as np
from PIL import Image, UnidentifiedImageError
import shapely
from shapely.geometry import Polygon
from shapely.ops import triangulate


RADIUS_M = 6371000.0
EPS = 1e-7


def _number(value, name):
    value = float(value)
    if not math.isfinite(value):
        raise ValueError(f'{name} must be finite')
    return value


def _sha(data):
    return hashlib.sha256(data).hexdigest()


def _safe_text(value):
    # Token 不應進入成果；這層亦保護不慎混入署名文字的 Mapillary Token。
    return re.sub(r'MLY\|[^\s"\'<>]+', '[redacted]', str(value))


def _attribution(entries):
    clean = []
    for entry in entries:
        item = {}
        for key in ('image_id', 'author', 'source_url', 'license', 'changes'):
            if key not in entry:
                continue
            value = entry[key]
            if key == 'source_url':
                url = urlsplit(str(value))
                if url.scheme not in ('https', 'http') or not url.hostname or url.username or url.password:
                    continue
                query = [(k, v) for k, v in parse_qsl(url.query) if k in ('pKey', 'image_id')]
                value = urlunsplit((url.scheme, url.netloc, url.path, urlencode(query), ''))
            elif isinstance(value, dict):
                value = {k: _safe_text(v) for k, v in value.items() if k in ('id', 'name', 'username')}
            elif isinstance(value, list):
                value = [_safe_text(v) for v in value if isinstance(v, (str, int, float))]
            else:
                value = _safe_text(value)
            item[key] = value
        if item:
            clean.append(item)
    return clean


def _wall_quality(walls):
    quality = []
    for wall in walls:
        item = {key: _safe_text(wall[key]) for key in ('key', 'status', 'tier', 'fuse_mode', 'texture_source') if key in wall}
        item['flags'] = [_safe_text(flag) for flag in wall.get('flags', [])]
        for key in ('confidence', 'unknown_share', 'observed_share', 'height_m', 'upstream_height_m',
                    'piece', 'n_pieces', 's_offset', 'hole_fraction', 'engine_observed_share'):
            if wall.get(key) is not None:
                item[key] = _number(wall[key], key)
        plane = wall.get('plane') or {}
        if plane:
            item['plane'] = {key: _safe_text(plane[key]) for key in ('source', 'pano') if key in plane}
            for key in ('offset_vs_osm_m', 'angle_vs_osm_deg', 'pts_per_m', 'rms_m', 'n_inliers'):
                if plane.get(key) is not None:
                    item['plane'][key] = _number(plane[key], key)
        quality.append(item)
    return quality


def _engine_quality(source):
    quality = {'registration': {}}
    registration = source.get('registration') or {}
    for key in ('panos', 'with_cluster', 'local', 'global', 'none', 'no_cluster', 'acceptance_rate_local',
                'acceptance_rate_any', 'median_shift_m', 'median_inliers_after', 'median_inliers_before', 'theta_used'):
        if registration.get(key) is not None:
            quality['registration'][key] = _number(registration[key], key)
    for key, counts in [('ground_sources', source.get('ground_sources', {})),
                        ('plane_sources', source.get('plane_sources', {}))]:
        quality[key] = {k: _number(v, k) for k, v in counts.items()
                        if re.fullmatch(r'[A-Za-z0-9_.-]{1,80}', k)}
    reasons = registration.get('local_rejection_reasons') or {}
    quality['registration']['local_rejection_reasons'] = {
        k: _number(v, k) for k, v in reasons.items()
        if re.fullmatch(r'[A-Za-z0-9_.-]{1,80}|[0-9]{1,4}/[0-9]{1,4}', k)}
    quality['warnings'] = [warning for warning in source.get('warnings', [])
                           if isinstance(warning, str) and re.fullmatch(r'[A-Z0-9_]{1,80}', warning)]
    return quality


class _Glb:
    def __init__(self):
        self.binary = bytearray()
        self.doc = {'asset': {'version': '2.0', 'generator': '3wa GeoTexture pilot exporter'},
                    'scene': 0, 'scenes': [{'nodes': [0]}], 'nodes': [{'mesh': 0}],
                    'meshes': [{'primitives': []}], 'bufferViews': [], 'accessors': [],
                    'materials': [], 'extensionsUsed': ['KHR_materials_unlit']}

    def view(self, data, target=None):
        self.binary.extend(b'\0' * (-len(self.binary) % 4))
        view = {'buffer': 0, 'byteOffset': len(self.binary), 'byteLength': len(data)}
        if target:
            view['target'] = target
        self.doc['bufferViews'].append(view)
        self.binary.extend(data)
        return len(self.doc['bufferViews']) - 1

    def accessor(self, values, columns, index=False):
        array = np.asarray(values, dtype='<u4' if index else '<f4').reshape(-1, columns)
        entry = {'bufferView': self.view(array.tobytes(), 34963 if index else 34962),
                 'componentType': 5125 if index else 5126, 'count': len(array),
                 'type': {1: 'SCALAR', 2: 'VEC2', 3: 'VEC3'}[columns]}
        if not index:
            entry['min'] = array.min(axis=0).tolist()
            entry['max'] = array.max(axis=0).tolist()
        self.doc['accessors'].append(entry)
        return len(self.doc['accessors']) - 1

    def material(self, image=None):
        pbr = {'baseColorFactor': [1, 1, 1, 1], 'metallicFactor': 0, 'roughnessFactor': 1}
        if image is not None:
            images = self.doc.setdefault('images', [])
            images.append({'bufferView': self.view(image), 'mimeType': 'image/png'})
            self.doc.setdefault('samplers', [{'magFilter': 9729, 'minFilter': 9729,
                                               'wrapS': 33071, 'wrapT': 33071}])
            textures = self.doc.setdefault('textures', [])
            textures.append({'source': len(images) - 1, 'sampler': 0})
            pbr['baseColorTexture'] = {'index': len(textures) - 1, 'texCoord': 0}
        material = {'pbrMetallicRoughness': pbr, 'alphaMode': 'OPAQUE', 'doubleSided': False}
        if image is not None:
            # 照片保留採樣顏色，白模則用 PBR 明暗呈現體積。
            material['extensions'] = {'KHR_materials_unlit': {}}
        self.doc['materials'].append(material)
        return len(self.doc['materials']) - 1

    def primitive(self, group):
        if not group['indices']:
            return
        attributes = {'POSITION': self.accessor(group['positions'], 3),
                      'NORMAL': self.accessor(group['normals'], 3)}
        if group['uvs']:
            attributes['TEXCOORD_0'] = self.accessor(group['uvs'], 2)
        self.doc['meshes'][0]['primitives'].append({
            'attributes': attributes, 'indices': self.accessor(group['indices'], 1, True),
            'material': group['material'], 'mode': 4, 'extras': group['extras']})

    def bytes(self):
        self.doc['buffers'] = [{'byteLength': len(self.binary)}]
        encoded = json.dumps(self.doc, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode('utf-8')
        encoded += b' ' * (-len(encoded) % 4)
        binary = bytes(self.binary) + b'\0' * (-len(self.binary) % 4)
        size = 12 + 8 + len(encoded) + 8 + len(binary)
        return (struct.pack('<4sII', b'glTF', 2, size) + struct.pack('<II', len(encoded), 0x4e4f534a)
                + encoded + struct.pack('<II', len(binary), 0x004e4942) + binary)


def _group(material, extras):
    return {'material': material, 'extras': extras, 'positions': [], 'normals': [], 'uvs': [], 'indices': []}


def _quad(group, points, normal, uvs=None, reverse=False):
    start = len(group['positions'])
    group['positions'].extend(points)
    group['normals'].extend([normal] * 4)
    if uvs is not None:
        group['uvs'].extend(uvs)
    triangles = [0, 1, 2, 0, 2, 3] if not reverse else [0, 2, 1, 0, 3, 2]
    group['indices'].extend(start + i for i in triangles)


def _load_texture(wall, folder, glb):
    if wall.get('status') != 'textured' or wall.get('tier') not in ('A', 'B'):
        return None
    if _number(wall.get('observed_share', 0), 'observed_share') <= 0 or not wall.get('tex_path'):
        return None
    path = Path(wall['tex_path'])
    if not path.is_absolute():
        path = folder / path
    # 上游 blocks 的 alpha 是類別，絕不能當觀測有效度或照片透明度。
    if 'blocks' in [part.lower() for part in path.parts] or path.stem.endswith('_cls'):
        return None
    try:
        raw = path.read_bytes()
        with Image.open(io.BytesIO(raw)) as source:
            source.load()
            if source.format not in ('PNG', 'JPEG'):
                return None
            rgba = source.convert('RGBA')
    except (OSError, UnidentifiedImageError, ValueError):
        return None
    alpha = np.asarray(rgba.getchannel('A'))
    valid_share = float(np.count_nonzero(alpha) / alpha.size)
    if valid_share == 0:
        return None
    opaque = Image.alpha_composite(Image.new('RGBA', rgba.size, (255, 255, 255, 255)), rgba).convert('RGB')
    stream = io.BytesIO()
    opaque.save(stream, format='PNG')
    encoded = stream.getvalue()
    return {'material': glb.material(encoded), 'valid_pixel_share': valid_share,
            '_valid_mask': alpha > 0,
            'observed_share': min(1.0, _number(wall['observed_share'], 'observed_share')),
            'source_sha256': _sha(raw), 'embedded_sha256': _sha(encoded),
            'width': rgba.width, 'height': rgba.height, 'tier': wall['tier'],
            'upstream_wall_key': _safe_text(wall['key'])}


def _valid_fraction(texture, u0, u1, v0, v1):
    """依原始有效像素的面積比例算裁切 coverage，避免沿用整張圖平均而高估。"""
    mask = texture['_valid_mask']
    h, w = mask.shape
    x0, x1 = sorted((max(0.0, min(float(w), u0 * w)), max(0.0, min(float(w), u1 * w))))
    y0, y1 = sorted((max(0.0, min(float(h), v0 * h)), max(0.0, min(float(h), v1 * h))))
    if x1 - x0 <= EPS or y1 - y0 <= EPS:
        return 0.0
    left, right, top, bottom = math.floor(x0), math.ceil(x1), math.floor(y0), math.ceil(y1)
    region = mask[top:bottom, left:right]
    if right - left == 1:
        rows = region[:, 0].astype(float) * (x1 - x0)
    else:
        rows = np.count_nonzero(region, axis=1).astype(float)
        rows -= (x0 - left) * region[:, 0]
        rows -= (right - x1) * region[:, -1]
    if bottom - top == 1:
        valid_area = rows[0] * (y1 - y0)
    else:
        valid_area = float(rows.sum() - (y0 - top) * rows[0] - (bottom - y1) * rows[-1])
    return max(0.0, min(1.0, valid_area / ((x1 - x0) * (y1 - y0))))


def _mapping(wall, pi, ri, ei, a, b, project):
    for source in wall.get('source_edges', []):
        if (int(source['polygon']), int(source['ring']), int(source['edge'])) == (pi, ri, ei):
            s0, s1 = _number(source['s0'], 's0'), _number(source['s1'], 's1')
            return (s1, s0) if source.get('reversed') else (s0, s1)
    if wall.get('source_edges'):
        return None
    wa, wb = project(wall['a_lonlat']), project(wall['b_lonlat'])
    delta = wb - wa
    length = float(np.linalg.norm(delta))
    if length < EPS:
        return None
    direction = delta / length
    edge = b - a
    if abs(float(np.dot(edge / np.linalg.norm(edge), direction))) < math.cos(math.radians(3)):
        return None
    # 未提供原始 edge 對照時只接受嚴格共線，避免窄巷相鄰牆互套。
    normal = np.array([-direction[1], direction[0]])
    if max(abs(float(np.dot(a - wa, normal))), abs(float(np.dot(b - wa, normal)))) > .15:
        return None
    factor = _number(wall.get('length_m', length), 'length_m') / length
    return float(np.dot(a - wa, direction) * factor), float(np.dot(b - wa, direction) * factor)


def _triangles(polygon):
    if hasattr(shapely, 'constrained_delaunay_triangles'):
        triangles = list(shapely.constrained_delaunay_triangles(polygon).geoms)
    else:
        triangles = [triangle for triangle in triangulate(polygon) if polygon.covers(triangle)]
    if (not triangles or any(not polygon.covers(triangle) for triangle in triangles)
            or abs(sum(t.area for t in triangles) - polygon.area) > max(1e-6, polygon.area * 1e-9)):
        raise ValueError('roof triangulation does not exactly cover source footprint; Shapely 2.1+ required')
    return triangles


def export_pilot(pilot_path, output_path) -> dict:
    """輸出 .glb 與同名 .manifest.json；不需網路、不讀 Token。"""
    pilot_path, output_path = Path(pilot_path).resolve(), Path(output_path).resolve()
    pilot = json.loads(pilot_path.read_text(encoding='utf-8-sig'))
    if pilot.get('version') != 1:
        raise ValueError('unsupported pilot version')
    feature = pilot['feature']
    geometry = feature['geometry']
    if geometry['type'] not in ('Polygon', 'MultiPolygon'):
        raise ValueError('source footprint must be Polygon or MultiPolygon')
    height = _number(feature['properties'].get('height'), 'official building height')
    if height <= 0:
        raise ValueError('official building height must be positive')
    lon = _number(pilot['origin']['longitude'], 'longitude')
    lat = _number(pilot['origin']['latitude'], 'latitude')
    if not -180 <= lon <= 180 or not -90 < lat < 90:
        raise ValueError('invalid origin')
    kx, ky = math.pi / 180 * RADIUS_M * math.cos(math.radians(lat)), math.pi / 180 * RADIUS_M

    def project(point):
        return np.array([(_number(point[0], 'longitude') - lon) * kx,
                         (_number(point[1], 'latitude') - lat) * ky])

    glb = _Glb()
    white = glb.material()
    records = []
    textures = []
    levels = {0.0, height}
    for wall in pilot.get('walls', []):
        texture = _load_texture(wall, pilot_path.parent, glb)
        if texture is None:
            continue
        extent = wall.get('extent', {})
        s_l, s_r = _number(extent['s_l'], 's_l'), _number(extent['s_r'], 's_r')
        h_bot = _number(extent.get('h_bot', 0), 'h_bot')
        h_top = _number(extent.get('h_top', wall['height_m']), 'h_top')
        if s_r <= s_l or h_top <= h_bot:
            raise ValueError('invalid texture extent')
        record = {'wall': wall, 'texture': texture, 's_l': s_l, 's_r': s_r,
                  'h_bot': h_bot, 'h_top': h_top}
        records.append(record)
        textures.append(texture)
        levels.update(max(0.0, min(height, h)) for h in (h_bot, h_top))
    records.sort(key=lambda r: (r['wall']['tier'] != 'A', -r['texture']['observed_share']))
    levels = sorted(levels)
    polygons = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
    surfaces = []
    groups = []
    total_wall_area = total_observed_area = 0.0
    textured_keys = set()
    for pi, source_rings in enumerate(polygons):
        expanded_rings = []
        for ri, source_ring in enumerate(source_rings):
            points = [project(p) for p in source_ring]
            if len(points) < 4 or not np.allclose(points[0], points[-1], atol=EPS, rtol=0):
                raise ValueError('source rings must be closed with at least three vertices')
            signed_area = sum(float(a[0] * b[1] - b[0] * a[1]) for a, b in zip(points, points[1:]))
            sign = 1 if (signed_area > 0) == (ri == 0) else -1
            expanded = []
            for ei, (a, b) in enumerate(zip(points, points[1:])):
                edge = b - a
                length = float(np.linalg.norm(edge))
                if length <= EPS:
                    raise ValueError('source footprint contains zero-length edge')
                key = f'source:p{pi}:r{ri}:e{ei}'
                candidates, cuts = [], {0.0, 1.0}
                for record in records:
                    mapping = _mapping(record['wall'], pi, ri, ei, a, b, project)
                    if mapping is None or abs(mapping[1] - mapping[0]) < EPS:
                        continue
                    s0, s1 = mapping
                    t0, t1 = sorted(((record['s_l'] - s0) / (s1 - s0), (record['s_r'] - s0) / (s1 - s0)))
                    lo, hi = max(0.0, t0), min(1.0, t1)
                    if hi - lo <= EPS:
                        continue
                    candidates.append((record, s0, s1, lo, hi))
                    cuts.update((lo, hi))
                cuts = sorted(cuts)
                edge_points = [a + edge * t for t in cuts]
                expanded.extend(edge_points[:-1])
                normal = [sign * edge[1] / length, 0, sign * edge[0] / length]
                grouped = {}
                mapped_area = observed_area = 0.0
                upstream_keys = set()
                for ci, (t0, t1) in enumerate(zip(cuts, cuts[1:])):
                    for low, high in zip(levels, levels[1:]):
                        tm, hm = (t0 + t1) / 2, (low + high) / 2
                        found, uv, valid_fraction = None, None, 0.0
                        for candidate in candidates:
                            record, s0, s1, lo, hi = candidate
                            if not (lo - EPS <= tm <= hi + EPS
                                    and record['h_bot'] - EPS <= hm <= record['h_top'] + EPS):
                                continue
                            u0 = (s0 + (s1 - s0) * t0 - record['s_l']) / (record['s_r'] - record['s_l'])
                            u1 = (s0 + (s1 - s0) * t1 - record['s_l']) / (record['s_r'] - record['s_l'])
                            v0 = (record['h_top'] - low) / (record['h_top'] - record['h_bot'])
                            v1 = (record['h_top'] - high) / (record['h_top'] - record['h_bot'])
                            valid_fraction = _valid_fraction(record['texture'], u0, u1, v0, v1)
                            if valid_fraction > EPS:
                                found = candidate
                                uv = [[u0, v0], [u1, v0], [u1, v1], [u0, v1]]
                                break
                        material = found[0]['texture']['material'] if found else white
                        if material not in grouped:
                            grouped[material] = _group(material, {'wall_key': key, 'textured': bool(found),
                                                                  'upstream_wall_keys': [], 'required_texture': True})
                        group = grouped[material]
                        p0, p1 = edge_points[ci], edge_points[ci + 1]
                        quad = [[p0[0], low, -p0[1]], [p1[0], low, -p1[1]],
                                [p1[0], high, -p1[1]], [p0[0], high, -p0[1]]]
                        if found:
                            record, s0, s1 = found[:3]
                            area = length * (t1 - t0) * (high - low)
                            mapped_area += area
                            observed_area += area * valid_fraction
                            upstream_key = _safe_text(record['wall']['key'])
                            upstream_keys.add(upstream_key)
                            if upstream_key not in group['extras']['upstream_wall_keys']:
                                group['extras']['upstream_wall_keys'].append(upstream_key)
                        _quad(group, quad, normal, uv, sign < 0)
                groups.extend(grouped.values())
                area = length * height
                is_textured = observed_area > EPS
                if is_textured:
                    textured_keys.add(key)
                total_wall_area += area
                total_observed_area += observed_area
                surfaces.append({'key': key, 'kind': 'wall', 'textured': is_textured,
                                 'area_m2': area, 'geometric_coverage': mapped_area / area,
                                 'coverage': observed_area / area, 'upstream_wall_keys': sorted(upstream_keys)})
            expanded.append(expanded[0])
            expanded_rings.append(expanded)
        polygon = Polygon(expanded_rings[0], expanded_rings[1:])
        if polygon.is_empty or not polygon.is_valid or polygon.area <= EPS:
            raise ValueError('source footprint polygon is invalid')
        triangles = _triangles(polygon)
        for kind, y, direction in [('roof', height, 1), ('base', 0.0, -1)]:
            key = f'{kind}:p{pi}'
            group = _group(white, {'kind': kind, 'surface_key': key, 'textured': False,
                                    'required_texture': kind == 'roof'})
            for triangle in triangles:
                xy = list(triangle.exterior.coords)[:3]
                area2 = sum(xy[i][0] * xy[(i + 1) % 3][1] - xy[(i + 1) % 3][0] * xy[i][1] for i in range(3))
                if area2 * direction < 0:
                    xy.reverse()
                start = len(group['positions'])
                group['positions'].extend([[x, y, -north] for x, north in xy])
                group['normals'].extend([[0, direction, 0]] * 3)
                group['indices'].extend([start, start + 1, start + 2])
            groups.append(group)
            if kind == 'roof':
                surfaces.append({'key': key, 'kind': kind, 'textured': False, 'area_m2': polygon.area,
                                 'geometric_coverage': 0, 'coverage': 0})
    for group in groups:
        glb.primitive(group)
    metadata = {'build_id': _safe_text(pilot['build_id']),
                'texture_status': 'partial' if textured_keys else 'missing',
                'verification_status': 'trial_unverified',
                'attribution': _attribution(pilot.get('attribution', [])),
                'wall_quality': _wall_quality(pilot.get('walls', [])),
                'engine_quality': _engine_quality(pilot.get('engine_quality') or {})}
    glb.doc['asset']['extras'] = {'geotexture': metadata}
    encoded = glb.bytes()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_bytes(encoded)
    wall_count = sum(s['kind'] == 'wall' for s in surfaces)
    manifest = {k: _safe_text(pilot[k]) for k in ('source_key', 'build_id', 'source_hash', 'recipe_key',
                                                 'engine_commit', 'imagery_hash')}
    manifest.update({'version': 1, 'surfaces_total': len(surfaces), 'surfaces_textured': len(textured_keys),
                     'walls_total': wall_count, 'walls_textured': len(textured_keys),
                     'walls_missing': wall_count - len(textured_keys),
                     'surfaces_missing': len(surfaces) - len(textured_keys),
                     'texture_status': metadata['texture_status'],
                     'verification_status': metadata['verification_status'],
                     'model_sha256': _sha(encoded), 'geometry_sha256': _sha(json.dumps(geometry, sort_keys=True,
                         separators=(',', ':'), ensure_ascii=False).encode('utf-8')),
                     'attribution': metadata['attribution'],
                     'longitude': lon, 'latitude': lat, 'altitude': 0, 'heading': 0, 'scale': 1,
                     'up_axis': 'Y', 'forward_axis': '-Z', 'local_axes': {'X': 'east', 'Y': 'up', 'Z': 'south'},
                     'wall_quality': metadata['wall_quality'],
                     'engine_quality': metadata['engine_quality'],
                     'surfaces': surfaces, 'textures': [{k: v for k, v in t.items() if k != 'material' and not k.startswith('_')}
                                                       for t in textures],
                     'wall_area_m2': total_wall_area, 'observed_wall_area_m2': total_observed_area,
                     'wall_coverage': total_observed_area / total_wall_area if total_wall_area else 0})
    manifest_path = output_path.with_suffix('.manifest.json')
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2, allow_nan=False) + '\n', encoding='utf-8')
    return {'model_path': str(output_path), 'manifest_path': str(manifest_path), **manifest}


def main(argv=None):
    parser = argparse.ArgumentParser(description='Export an orthofacade pilot to a self-contained GLB')
    parser.add_argument('pilot_path', type=Path)
    parser.add_argument('output_path', type=Path)
    args = parser.parse_args(argv)
    result = export_pilot(args.pilot_path, args.output_path)
    print(json.dumps({key: result[key] for key in ('model_path', 'manifest_path', 'texture_status',
                     'walls_total', 'walls_textured', 'surfaces_total', 'surfaces_textured')}, ensure_ascii=False))


if __name__ == '__main__':
    main()
