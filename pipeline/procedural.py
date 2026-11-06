"""Offline, deterministic synthetic facades on the supplied footprint and height.

Coordinates follow glb_export: X east, Y up, Z south, in metres. These textures
are illustrations, never photographic observations or evidence of real windows.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import random
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw
from shapely import set_precision
from shapely.geometry import MultiPolygon, Polygon, Point, shape
from shapely.ops import transform
from shapely.affinity import scale

from .glb_export import EPS, RADIUS_M, _Glb, _group, _quad, _triangles


RECIPES = ('procedural-tw-v1', 'procedural-modern-v1', 'procedural-pastel-v1')
MAX_INPUT_BYTES = 8 * 1024 * 1024
MAX_VERTICES = 20000
TEXTURE_SIZE = 512


def _number(value, name):
    if isinstance(value, bool) or not isinstance(value, (int, float, str)):
        raise ValueError(f'{name} must be a finite number')
    try:
        value = float(value)
    except (ValueError, OverflowError) as error:
        raise ValueError(f'{name} must be a finite number') from error
    if not math.isfinite(value):
        raise ValueError(f'{name} must be a finite number')
    return value


def _source(request):
    if not isinstance(request, dict) or request.get('recipe_key') not in RECIPES:
        raise ValueError('unsupported procedural recipe')
    for name in ('source_key', 'build_id', 'source_hash'):
        if not isinstance(request.get(name), str) or not 1 <= len(request[name]) <= 512:
            raise ValueError(f'invalid {name}')
    feature = request.get('feature')
    if not isinstance(feature, dict) or not isinstance(feature.get('properties'), dict):
        raise ValueError('feature must contain official building height')
    height = _number(feature['properties'].get('height'), 'official building height')
    if height <= 0 or height > np.finfo(np.float32).max:
        raise ValueError('official building height must be positive and representable')
    geometry = feature.get('geometry')
    if not isinstance(geometry, dict) or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
        raise ValueError('source geometry must be Polygon or MultiPolygon')
    coordinates = geometry.get('coordinates')
    if not isinstance(coordinates, list) or not coordinates:
        raise ValueError('source footprint coordinates are missing')
    polygons = [coordinates] if geometry['type'] == 'Polygon' else coordinates
    if len(polygons) > 256:
        raise ValueError('source footprint has too many polygons')
    all_points, checked = [], []
    for rings in polygons:
        if not isinstance(rings, list) or not rings:
            raise ValueError('source footprint rings are missing')
        checked_rings = []
        for ring in rings:
            if not isinstance(ring, list) or len(ring) < 4:
                raise ValueError('source rings must have at least three vertices and closure')
            if len(all_points) + len(ring) > MAX_VERTICES:
                raise ValueError('source footprint exceeds vertex limit')
            points = []
            for p in ring:
                if not isinstance(p, list) or len(p) not in (2, 3):
                    raise ValueError('invalid footprint coordinate')
                point = [_number(value, 'footprint coordinate') for value in p]
                if not -180 <= point[0] <= 180 or not -90 < point[1] < 90:
                    raise ValueError('footprint coordinate outside longitude/latitude range')
                points.append(point[:2])
            if points[0] != points[-1]:
                raise ValueError('source rings must be closed')
            if any(a == b for a, b in zip(points, points[1:])):
                raise ValueError('source footprint contains zero-length edge')
            checked_rings.append(points)
            all_points.extend(points)
        checked.append(checked_rings)
    lon_min, lat_min = np.min(all_points, axis=0)
    lon_max, lat_max = np.max(all_points, axis=0)
    if lon_max - lon_min > 180:
        raise ValueError('source footprint crosses unsupported antimeridian')
    lon, lat = float((lon_min + lon_max) / 2), float((lat_min + lat_max) / 2)
    k = math.pi / 180 * RADIUS_M
    kx = k * math.cos(math.radians(lat))
    projected, shapes = [], []
    for rings in checked:
        local = [np.array([[(x - lon) * kx, (y - lat) * k] for x, y in ring]) for ring in rings]
        polygon = Polygon(local[0], local[1:])
        if not polygon.is_valid or polygon.is_empty or polygon.area <= EPS:
            raise ValueError('source footprint polygon is invalid')
        if any(np.linalg.norm(b - a) <= EPS for ring in local for a, b in zip(ring, ring[1:])):
            raise ValueError('source footprint contains zero-length edge')
        projected.append(local)
        shapes.append(polygon)
    if not MultiPolygon(shapes).is_valid:
        raise ValueError('source footprint polygons overlap or are invalid')
    return height, lon, lat, projected, shapes


def _station_parts(request, height, lon, lat, polygons):
    correction = request['feature']['properties'].get('geometry_correction')
    if correction is None:
        return None
    if not isinstance(correction, dict) or correction.get('version') != 1 or correction.get('estimated') is not True:
        raise ValueError('invalid station correction')
    if any(correction.get(k) != request[k] for k in ('source_key', 'build_id')):
        raise ValueError('station correction identity mismatch')
    base = _number(correction.get('base_m'), 'station clearance')
    width = _number(correction.get('width_scale'), 'station width scale')
    if not 0 < base < min(height, 15.000001) or not .1 <= width <= 3:
        raise ValueError('station clearance or width outside supported range')
    geometry = correction.get('geometry')
    if not isinstance(geometry, dict) or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
        raise ValueError('station mask must be polygonal')
    raw = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
    points = [p for rings in raw for ring in rings for p in ring]
    if not points or len(points) > MAX_VERTICES:
        raise ValueError('station mask exceeds vertex limit')
    for point in points:
        if len(point) != 2 or not -180 <= _number(point[0], 'mask longitude') <= 180 or not -90 < _number(point[1], 'mask latitude') < 90:
            raise ValueError('invalid station mask coordinate')
    mask = shape(geometry)
    if mask.is_empty or not mask.is_valid:
        raise ValueError('invalid station mask geometry')
    k = math.pi / 180 * RADIUS_M
    mask = transform(lambda x, y, z=None: ((np.asarray(x)-lon)*k*math.cos(math.radians(lat)), (np.asarray(y)-lat)*k), mask)
    if width != 1:
        mask = scale(mask, xfact=width, yfact=width, origin='centroid')
    # Sub-millimetre boolean slivers collapse in GLB float32; use a 1mm grid for station cuts.
    mask = set_precision(mask, .001)
    parts = []
    def append(geometry, floor, boundary):
        if geometry.is_empty:
            return
        if geometry.geom_type != 'Polygon':
            for item in getattr(geometry, 'geoms', []):
                append(item, floor, boundary)
            return
        if geometry.area <= EPS:
            return
        rings = [np.array(r.coords) for r in [geometry.exterior, *geometry.interiors]]
        parts.append((rings, geometry, floor, boundary, base))
    cut_area = 0
    # shortcut: masks and 6m clearance are illustrative; replace with surveyed station geometry when available.
    for polygon in polygons:
        polygon = set_precision(polygon, .001)
        cut = polygon.intersection(mask)
        cut_area += cut.area
        append(polygon.difference(mask), 0, polygon.boundary)
        append(cut, base, polygon.boundary)
    if cut_area <= EPS:
        raise ValueError('station opening does not intersect this building')
    if sum(len(r) for rings, *_ in parts for r in rings) > MAX_VERTICES:
        raise ValueError('station cut exceeds vertex limit')
    return parts


def _shade(color, amount):
    return tuple(max(0, min(255, c + amount)) for c in color)


def _png(image):
    stream = io.BytesIO()
    image.save(stream, format='PNG', optimize=False)
    return stream.getvalue()


def _facade(recipe, rng):
    """Two bays by two floors; dimensions stay fixed in metres, not per wall."""
    size, cell = TEXTURE_SIZE, TEXTURE_SIZE // 2
    if recipe == 'procedural-tw-v1':
        color = rng.choice([(202, 188, 166), (192, 166, 153), (183, 191, 173), (199, 190, 175)])
        repeat = (6.4, 6.4)
    elif recipe == 'procedural-modern-v1':
        color = rng.choice([(79, 111, 128), (91, 120, 133), (96, 119, 119)])
        repeat = (7.2, 7.2)
    else:
        color = rng.choice([(232, 185, 162), (171, 204, 189), (216, 198, 222), (232, 214, 165)])
        repeat = (6.4, 6.4)
    image = Image.new('RGB', (size, size), color)
    draw = ImageDraw.Draw(image)
    if recipe == 'procedural-tw-v1':
        for y in range(0, size, 12):
            for x in range(0, size, 16):
                draw.rectangle((x, y, x + 14, y + 10), fill=_shade(color, rng.randint(-9, 9)))
    for row in range(2):
        for column in range(2):
            x, y = column * cell, row * cell
            if recipe == 'procedural-modern-v1':
                # Deep spandrels, metal mullions, and different glazing reflections.
                draw.rectangle((x, y, x + 255, y + 255), fill=(48, 66, 73))
                for pane in range(3):
                    left, right = x + 12 + pane * 80, x + 86 + pane * 80
                    tint = rng.randint(-10, 10)
                    for dy in range(18, 239):
                        draw.line((left, y + dy, right, y + dy),
                                  fill=_shade(color, tint + round((239 - dy) * .18)))
                    draw.polygon([(left, y + 19), (right, y + 19), (right, y + 92),
                                  (left, y + 148)], fill=_shade(color, tint + 31))
                    draw.line((left, y + 18, left, y + 238), fill=(177, 193, 195), width=2)
                    draw.line((left, y + 178, right, y + 178), fill=(65, 85, 91), width=3)
                draw.rectangle((x, y + 246, x + 255, y + 251), fill=(146, 157, 157))
            elif recipe == 'procedural-pastel-v1':
                # Deliberately illustrated proportions, softened corners and flat shadows.
                ink, frame, glass = (82, 95, 101), (248, 241, 218), (104, 153, 167)
                draw.rectangle((x, y, x + 255, y + 13), fill=_shade(color, -32))
                draw.line((x, y + 15, x + 255, y + 15), fill=_shade(color, 21), width=4)
                draw.rounded_rectangle((x + 46, y + 53, x + 222, y + 209), radius=7,
                                       fill=_shade(color, -29))
                draw.rounded_rectangle((x + 35, y + 42, x + 211, y + 198), radius=8,
                                       fill=frame, outline=ink, width=4)
                draw.rectangle((x + 48, y + 55, x + 198, y + 184), fill=_shade(glass, rng.randint(-12, 12)))
                draw.polygon([(x + 51, y + 58), (x + 122, y + 58), (x + 51, y + 127)],
                             fill=(167, 203, 204))
                draw.line((x + 123, y + 55, x + 123, y + 184), fill=frame, width=9)
                draw.line((x + 48, y + 116, x + 198, y + 116), fill=frame, width=7)
                draw.rectangle((x + 28, y + 197, x + 218, y + 210), fill=ink)
                draw.rectangle((x + 28, y + 196, x + 218, y + 202), fill=frame)
            else:
                # Ceramic tile cladding, balcony rails, aluminum windows and small AC grilles.
                frame, glass = (217, 216, 198), (57, 81, 83)
                draw.rectangle((x, y, x + 255, y + 15), fill=_shade(color, -39))
                draw.line((x, y + 16, x + 255, y + 16), fill=_shade(color, 25), width=3)
                draw.rectangle((x + 27, y + 48, x + 229, y + 205), fill=_shade(color, -52))
                draw.rectangle((x + 30, y + 45, x + 222, y + 198), fill=frame)
                draw.rectangle((x + 38, y + 53, x + 214, y + 190), fill=_shade(glass, rng.randint(-6, 9)))
                draw.polygon([(x + 41, y + 56), (x + 211, y + 56), (x + 211, y + 90),
                              (x + 41, y + 137)], fill=(91, 117, 116))
                draw.line((x + 126, y + 53, x + 126, y + 191), fill=frame, width=6)
                draw.line((x + 38, y + 100, x + 214, y + 100), fill=frame, width=4)
                draw.rectangle((x + 21, y + 194, x + 231, y + 215), fill=_shade(color, -26))
                for rail in range(28, 231, 22):
                    draw.line((x + rail, y + 182, x + rail, y + 213), fill=(95, 99, 94), width=2)
                draw.line((x + 24, y + 182, x + 230, y + 182), fill=(207, 211, 199), width=3)
                draw.rectangle((x + 166, y + 219, x + 226, y + 243), fill=(208, 208, 192), outline=(124, 129, 118))
                for grille in range(223, 241, 4):
                    draw.line((x + 171, y + grille, x + 221, y + grille), fill=(133, 142, 132))
    return _png(image), repeat


def _roof(recipe, rng):
    color = {'procedural-tw-v1': (139, 148, 137), 'procedural-modern-v1': (128, 140, 144),
             'procedural-pastel-v1': (185, 146, 131)}[recipe]
    image = Image.new('RGB', (TEXTURE_SIZE, TEXTURE_SIZE), _shade(color, -22))
    draw = ImageDraw.Draw(image)
    for y in range(0, TEXTURE_SIZE, 64):
        for x in range(0, TEXTURE_SIZE, 64):
            draw.rectangle((x + 1, y + 1, x + 62, y + 62), fill=_shade(color, rng.randint(-13, 13)))
            draw.line((x + 2, y + 2, x + 61, y + 2), fill=_shade(color, 16), width=2)
    return _png(image)


def generate(input_path, output_path):
    """Write a self-contained GLB and .manifest.json; no network or photo inputs."""
    input_path, output_path = Path(input_path).resolve(), Path(output_path).resolve()
    if input_path.stat().st_size > MAX_INPUT_BYTES:
        raise ValueError('procedural input exceeds size limit')
    request = json.loads(input_path.read_text(encoding='utf-8-sig'))
    return generate_request(request, output_path)


def generate_request(request, output_path):
    """Generate from an in-memory request using the same validation and bytes."""
    output_path = Path(output_path).resolve()
    height, lon, lat, rings_by_polygon, polygons = _source(request)
    # Triangulate before creating output, preserving concavities and courtyard holes.
    station_parts = _station_parts(request, height, lon, lat, polygons)
    parts = station_parts or [(rings, polygon, 0, None, 0) for rings, polygon in zip(rings_by_polygon, polygons)]
    triangles_by_polygon = [_triangles(part[1]) for part in parts]
    seed = hashlib.sha256(json.dumps([request[k] for k in ('source_key', 'build_id', 'recipe_key')],
                                     ensure_ascii=False, separators=(',', ':')).encode()).digest()
    rng = random.Random(int.from_bytes(seed, 'big'))
    wall_image, repeat = _facade(request['recipe_key'], rng)
    roof_image = _roof(request['recipe_key'], rng)
    glb = _Glb()
    glb.doc['asset']['generator'] = '3wa GeoTexture procedural facade v1'
    base_material = glb.material()
    wall_material, roof_material = glb.material(wall_image), glb.material(roof_image)
    # The photo helper uses unlit/clamped textures; synthetic facades need PBR/repeat.
    glb.doc.pop('extensionsUsed', None)
    for material in glb.doc['materials']:
        material.pop('extensions', None)
    glb.doc['materials'][base_material]['pbrMetallicRoughness']['baseColorFactor'] = [.48, .50, .48, 1]
    glb.doc['materials'][wall_material]['pbrMetallicRoughness']['roughnessFactor'] = (
        .48 if request['recipe_key'] == 'procedural-modern-v1' else .88)
    glb.doc['samplers'][0].update({'wrapS': 10497, 'wrapT': 10497, 'minFilter': 9987})
    groups = {kind: _group(material, {'kind': kind, 'textured': kind != 'base',
                                     'required_texture': kind != 'base', 'surface_ranges': []})
              for kind, material in [('wall', wall_material), ('roof', roof_material), ('base', base_material)]}
    surfaces = []
    for pi, ((rings, polygon, base, boundary, clearance), triangles) in enumerate(zip(parts, triangles_by_polygon)):
        for ri, ring in enumerate(rings):
            signed_area = sum(float(a[0] * b[1] - b[0] * a[1]) for a, b in zip(ring, ring[1:]))
            sign = 1 if (signed_area > 0) == (ri == 0) else -1
            for ei, (a, b) in enumerate(zip(ring, ring[1:])):
                edge = b - a
                length = float(np.linalg.norm(edge))
                if length <= EPS:
                    continue
                top = height
                if boundary is not None and boundary.distance(Point((a + b) / 2)) > .002:
                    if base > 0:
                        continue
                    top = clearance
                key = f'source:p{pi}:r{ri}:e{ei}'
                group = groups['wall']
                index_offset = len(group['indices'])
                normal = [sign * edge[1] / length, 0, sign * edge[0] / length]
                points = [[a[0], base, -a[1]], [b[0], base, -b[1]],
                          [b[0], top, -b[1]], [a[0], top, -a[1]]]
                # Align floor bands from ground level for every wall, independent of width.
                u, v = length / repeat[0], top / repeat[1]
                _quad(group, points, normal, [[0, -base / repeat[1] if base else 0], [u, -base / repeat[1] if base else 0], [u, -v], [0, -v]], sign < 0)
                group['extras']['surface_ranges'].append({'key': key, 'index_offset': index_offset,
                                                         'index_count': len(group['indices']) - index_offset})
                surfaces.append({'key': key, 'kind': 'wall', 'textured': True, 'area_m2': length * (top - base),
                                 'geometric_coverage': 1, 'coverage': 1})
        for kind, y, direction in [('roof', height, 1), ('base', base, -1)]:
            key = f'{kind}:p{pi}'
            group = groups[kind]
            index_offset = len(group['indices'])
            for triangle in triangles:
                xy = list(triangle.exterior.coords)[:3]
                area2 = sum(xy[i][0] * xy[(i + 1) % 3][1] - xy[(i + 1) % 3][0] * xy[i][1] for i in range(3))
                if area2 * direction < 0:
                    xy.reverse()
                start = len(group['positions'])
                group['positions'].extend([[x, y, -north] for x, north in xy])
                group['normals'].extend([[0, direction, 0]] * 3)
                group['indices'].extend([start, start + 1, start + 2])
                if kind == 'roof':
                    group['uvs'].extend([[x / 8, -north / 8] for x, north in xy])
            group['extras']['surface_ranges'].append({'key': key, 'index_offset': index_offset,
                                                     'index_count': len(group['indices']) - index_offset})
            if kind == 'roof':
                surfaces.append({'key': key, 'kind': 'roof', 'textured': True, 'area_m2': polygon.area,
                                 'geometric_coverage': 1, 'coverage': 1})
    for group in groups.values():
        glb.primitive(group)
    walls = sum(s['kind'] == 'wall' for s in surfaces)
    metadata = {k: request[k] for k in ('source_key', 'build_id', 'source_hash', 'recipe_key')}
    metadata.update({'version': 1, 'appearance_kind': 'procedural', 'verification_status': 'synthetic',
                     'style_key': request['recipe_key'], 'height_m': height, 'texture_status': 'complete',
                     'longitude': lon, 'latitude': lat, 'altitude': 0, 'heading': 0, 'scale': 1,
                     'up_axis': 'Y', 'forward_axis': '-Z', 'local_axes': {'X': 'east', 'Y': 'up', 'Z': 'south'},
                     'walls_total': walls, 'walls_textured': walls, 'walls_missing': 0,
                     'surfaces_total': len(surfaces), 'surfaces_textured': len(surfaces), 'surfaces_missing': 0,
                     'surfaces': surfaces, 'textures': [{'embedded_sha256': hashlib.sha256(raw).hexdigest(),
                                                       'width': TEXTURE_SIZE, 'height': TEXTURE_SIZE}
                                                      for raw in (wall_image, roof_image)]})
    if station_parts is not None:
        metadata['geometry_correction'] = request['feature']['properties']['geometry_correction']
    glb.doc['asset']['extras'] = {'geotexture': metadata}
    encoded = glb.bytes()
    manifest = {**metadata, 'model_sha256': hashlib.sha256(encoded).hexdigest()}
    # mkdir(mode=...) honors umask; set modes only on newly created directories.
    missing = []
    folder = output_path.parent
    while not folder.exists():
        missing.append(folder)
        folder = folder.parent
    for folder in reversed(missing):
        folder.mkdir(mode=0o755)
        folder.chmod(0o755)
    output_path.write_bytes(encoded)
    manifest_path = output_path.with_suffix('.manifest.json')
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2, allow_nan=False) + '\n', encoding='utf-8')
    return {'model_path': str(output_path), 'manifest_path': str(manifest_path), **manifest}


def _batch():
    while True:
        line = sys.stdin.buffer.readline(MAX_INPUT_BYTES + 1)
        if not line:
            return 0
        if len(line) > MAX_INPUT_BYTES:
            print(json.dumps({'ok': False, 'error': 'procedural input exceeds size limit'}), flush=True)
            return 1
        try:
            item = json.loads(line)
            if not isinstance(item, dict):
                raise ValueError('batch item must be an object')
            output = item.get('output_path')
            if not isinstance(output, str) or not Path(output).is_absolute() or Path(output).suffix != '.glb':
                raise ValueError('output_path must be an absolute .glb path')
            generate_request(item.get('request'), output)
            result = {'ok': True}
        except Exception as error:
            # A malformed or failed item must not prevent later buildings from running.
            result = {'ok': False, 'error': (str(error) or type(error).__name__)[:512]}
        print(json.dumps(result), flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description='Generate explicitly synthetic facades on real building geometry')
    parser.add_argument('input_path', type=Path, nargs='?')
    parser.add_argument('output_path', type=Path, nargs='?')
    parser.add_argument('--batch', action='store_true', help='read bounded NDJSON requests from stdin')
    args = parser.parse_args(argv)
    if args.batch:
        if args.input_path is not None or args.output_path is not None:
            parser.error('--batch cannot be combined with input_path or output_path')
        parser.exit(_batch())
    if args.input_path is None or args.output_path is None:
        parser.error('input_path and output_path are required unless --batch is used')
    try:
        result = generate(args.input_path, args.output_path)
    except (ValueError, OSError) as error:
        parser.exit(1, f'Procedural generation failed: {error}\n')
    print(json.dumps({k: result[k] for k in ('model_path', 'manifest_path', 'texture_status', 'appearance_kind',
                                          'walls_total', 'walls_textured', 'surfaces_total', 'surfaces_textured')},
                     ensure_ascii=False))


if __name__ == '__main__':
    main()
