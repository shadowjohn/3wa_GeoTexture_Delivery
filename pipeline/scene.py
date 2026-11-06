"""Bounded, offline shared-atlas scenes from canonical procedural building GLBs."""
from __future__ import annotations

import argparse
import copy
import hashlib
import io
import json
import math
import re
import shutil
import struct
import tempfile
from contextlib import ExitStack
from pathlib import Path

import numpy as np
from PIL import Image

from .glb_export import RADIUS_M, _Glb
from .procedural import RECIPES


MAX_BUILDINGS = 2000
MAX_EXTENT = 20000
MAX_TRIANGLES = 2000000
MAX_GLB_BYTES = 256 * 1024 * 1024
MAX_SOURCE_BYTES = 32 * 1024 * 1024
MAX_INPUT_BYTES = 8 * 1024 * 1024
MAX_CLIP_CELLS = 16384
MAX_CLIP_WORK = 8000000
ATLAS_EDGE = 4096
GUTTER = 2
IDENTITY = ('asset_id', 'source_key', 'build_id', 'source_hash', 'recipe_key', 'longitude', 'latitude')


def _require(condition, message):
    if not condition:
        raise ValueError(message)


def _integer(value, minimum=0):
    return type(value) is int and value >= minimum


def _coordinate(value, latitude=False):
    _require(type(value) in (int, float) and math.isfinite(value), 'coordinate must be finite')
    _require(abs(value) < 90 if latitude else abs(value) <= 180, 'coordinate outside longitude/latitude range')
    return float(value)


def _request(request):
    _require(isinstance(request, dict) and request.get('version') == 1, 'unsupported scene version')
    _require(request.get('kind') in ('preview', 'export'), 'unsupported scene kind')
    _require(request.get('recipe_key') in RECIPES, 'unsupported scene recipe')
    _require(isinstance(request.get('scene_id'), str) and re.fullmatch('[0-9a-f]{64}', request['scene_id']),
             'invalid scene_id')
    origin = request.get('origin')
    _require(isinstance(origin, dict), 'origin is required')
    lon, lat = _coordinate(origin.get('longitude')), _coordinate(origin.get('latitude'), True)
    _require(abs(lat) < 85, 'scene latitude exceeds supported projection')
    entries = request.get('entries')
    _require(isinstance(entries, list) and 1 <= len(entries) <= MAX_BUILDINGS, 'building count exceeds limit')
    ids, sources = set(), set()
    for entry in entries:
        _require(isinstance(entry, dict), 'invalid scene entry')
        for key, length in [('asset_id', 32), ('sha256', 64), ('source_hash', 64)]:
            _require(isinstance(entry.get(key), str) and re.fullmatch(f'[0-9a-f]{{{length}}}', entry[key]),
                     f'invalid {key}')
        for key in ('source_key', 'build_id'):
            _require(isinstance(entry.get(key), str) and 1 <= len(entry[key]) <= 512, f'invalid {key}')
        _require(entry.get('recipe_key') == request['recipe_key'], 'entry recipe does not match scene')
        _coordinate(entry.get('longitude'))
        _require(abs(_coordinate(entry.get('latitude'), True)) < 85, 'building latitude exceeds supported projection')
        _require(isinstance(entry.get('path'), str) and Path(entry['path']).is_absolute(),
                 'source path must be absolute')
        identity = (entry['source_key'], entry['build_id'])
        _require(entry['asset_id'] not in ids and identity not in sources, 'duplicate building identity')
        ids.add(entry['asset_id'])
        sources.add(identity)
    return lon, lat, entries


def _view(doc, binary, index):
    _require(_integer(index) and index < len(doc['bufferViews']), 'invalid bufferView index')
    view = doc['bufferViews'][index]
    offset, size = view.get('byteOffset', 0), view.get('byteLength')
    _require(view.get('buffer') == 0 and _integer(offset) and _integer(size, 1)
             and offset + size <= len(binary), 'bufferView exceeds buffer bounds')
    _require('byteStride' not in view and 'extensions' not in view, 'unsupported source bufferView layout')
    return memoryview(binary)[offset:offset + size]


def _accessor(doc, binary, index, columns, indices=False):
    _require(_integer(index) and index < len(doc['accessors']), 'invalid accessor index')
    accessor = doc['accessors'][index]
    count, offset = accessor.get('count'), accessor.get('byteOffset', 0)
    _require(_integer(count, 1) and count <= MAX_TRIANGLES * 3 and _integer(offset), 'invalid accessor bounds')
    _require(accessor.get('componentType') == (5125 if indices else 5126)
             and accessor.get('type') == {1: 'SCALAR', 2: 'VEC2', 3: 'VEC3'}[columns]
             and not any(k in accessor for k in ('sparse', 'normalized', 'extensions')), 'unsupported accessor layout')
    view = _view(doc, binary, accessor.get('bufferView'))
    _require(offset % 4 == 0 and offset + count * columns * 4 <= len(view), 'accessor exceeds buffer bounds')
    values = np.frombuffer(view, dtype='<u4' if indices else '<f4', count=count * columns,
                           offset=offset).reshape(count, columns)
    _require(np.isfinite(values).all(), 'accessor contains nonfinite data')
    if not indices:
        for key, actual in [('min', values.min(axis=0)), ('max', values.max(axis=0))]:
            declared = accessor.get(key)
            _require(isinstance(declared, list) and len(declared) == columns
                     and np.isfinite(declared).all() and np.allclose(declared, actual, rtol=1e-6, atol=1e-6),
                     'accessor declared bounds do not match data')
    return values


def _load(entry):
    path = Path(entry['path'])
    _require(path.is_file(), 'source path must be a regular file')
    with path.open('rb') as stream:
        raw = stream.read(MAX_SOURCE_BYTES + 1)
    _require(28 <= len(raw) <= MAX_SOURCE_BYTES, 'source GLB size exceeds limit')
    _require(hashlib.sha256(raw).hexdigest() == entry['sha256'], 'source SHA256 mismatch')
    _require(struct.unpack_from('<4sII', raw) == (b'glTF', 2, len(raw)), 'invalid GLB header')
    length, kind = struct.unpack_from('<II', raw, 12)
    _require(kind == 0x4e4f534a and length % 4 == 0 and length <= MAX_INPUT_BYTES
             and 28 + length <= len(raw), 'invalid GLB JSON chunk')
    doc = json.loads(raw[20:20 + length])
    offset = 20 + length
    size, kind = struct.unpack_from('<II', raw, offset)
    _require(kind == 0x004e4942 and size % 4 == 0 and offset + 8 + size == len(raw), 'invalid GLB binary chunk')
    binary = memoryview(raw)[offset + 8:]
    _require(doc.get('asset', {}).get('version') == '2.0'
             and doc['asset'].get('generator') == '3wa GeoTexture procedural facade v1', 'source is not canonical procedural GLB')
    _require(doc.get('scene') == 0 and doc.get('scenes') == [{'nodes': [0]}]
             and doc.get('nodes') == [{'mesh': 0}] and len(doc.get('meshes', [])) == 1,
             'source nodes or transforms are not canonical')
    _require(not any(doc.get(key) for key in ('animations', 'skins', 'extensions', 'extensionsRequired', 'extensionsUsed')),
             'unsupported source extensions or animation')
    buffers = doc.get('buffers', [])
    _require(len(buffers) == 1 and set(buffers[0]) == {'byteLength'}
             and _integer(buffers[0]['byteLength']) and 0 <= len(binary) - buffers[0]['byteLength'] <= 3,
             'external resources or invalid buffer length')
    binary = binary[:buffers[0]['byteLength']]
    metadata = doc['asset'].get('extras', {}).get('geotexture', {})
    expected = {'version': 1, 'appearance_kind': 'procedural', 'verification_status': 'synthetic',
                'style_key': entry['recipe_key'], 'texture_status': 'complete', 'altitude': 0,
                'heading': 0, 'scale': 1, 'up_axis': 'Y', 'forward_axis': '-Z',
                'local_axes': {'X': 'east', 'Y': 'up', 'Z': 'south'}}
    expected.update({k: entry[k] for k in IDENTITY if k != 'asset_id'})
    _require(all(metadata.get(k) == v for k, v in expected.items()), 'canonical source metadata mismatch')
    height = metadata.get('height_m')
    _require(type(height) in (int, float) and math.isfinite(height) and 0 < height <= MAX_EXTENT,
             'source height exceeds extent limit')
    if 'geometry_correction' in metadata:
        _require(isinstance(metadata['geometry_correction'], dict), 'invalid geometry correction provenance')
        json.dumps(metadata['geometry_correction'], allow_nan=False)
    images, textures, materials = doc.get('images', []), doc.get('textures', []), doc.get('materials', [])
    _require(len(images) == len(textures) == len(metadata.get('textures', [])) == 2 and len(materials) == 3,
             'source material/image layout is not canonical')
    _require(doc.get('samplers') == [{'magFilter': 9729, 'minFilter': 9987, 'wrapS': 10497, 'wrapT': 10497}],
             'source sampler is not canonical repeat sampling')
    image_refs = []
    for i, image in enumerate(images):
        _require(set(image) == {'bufferView', 'mimeType'} and image['mimeType'] == 'image/png'
                 and textures[i] == {'source': i, 'sampler': 0}, 'external or noncanonical image resource')
        content = _view(doc, binary, image['bufferView'])
        digest = hashlib.sha256(content).hexdigest()
        _require(metadata['textures'][i] == {'embedded_sha256': digest, 'width': 512, 'height': 512},
                 'source image integrity mismatch')
        with Image.open(io.BytesIO(content)) as decoded:
            _require(decoded.format == 'PNG' and decoded.size == (512, 512), 'invalid source image dimensions')
            decoded.verify()
        view = doc['bufferViews'][image['bufferView']]
        image_refs.append((digest, entry['path'], offset + 8 + view.get('byteOffset', 0), len(content)))
    for i, material in enumerate(materials):
        pbr = {'baseColorFactor': [.48, .50, .48, 1] if i == 0 else [1, 1, 1, 1],
               'metallicFactor': 0, 'roughnessFactor': (.48 if entry['recipe_key'] == 'procedural-modern-v1' else .88) if i == 1 else 1}
        if i:
            pbr['baseColorTexture'] = {'index': i - 1, 'texCoord': 0}
        _require(material == {'pbrMetallicRoughness': pbr, 'alphaMode': 'OPAQUE', 'doubleSided': False},
                 'source material is not canonical')
    primitives = doc['meshes'][0].get('primitives', [])
    _require(1 <= len(primitives) <= 60000, 'invalid source primitive count')
    checked = []
    for primitive in primitives:
        extras = primitive.get('extras', {})
        kind = extras.get('kind')
        _require(kind in ('wall', 'roof', 'base') and primitive.get('mode') == 4
                 and primitive.get('material') == {'wall': 1, 'roof': 2, 'base': 0}[kind]
                 and not any(key in primitive for key in ('extensions', 'targets')), 'noncanonical source primitive')
        attrs = primitive.get('attributes', {})
        _require(set(attrs) == ({'POSITION', 'NORMAL'} if kind == 'base' else {'POSITION', 'NORMAL', 'TEXCOORD_0'}),
                 'unsupported source attributes')
        positions = _accessor(doc, binary, attrs['POSITION'], 3)
        normals = _accessor(doc, binary, attrs['NORMAL'], 3)
        indices = _accessor(doc, binary, primitive.get('indices'), 1, True).reshape(-1)
        _require(len(indices) % 3 == 0 and indices.max() < len(positions), 'triangle index exceeds vertex bounds')
        _require(len(normals) == len(positions) and np.allclose(np.linalg.norm(normals, axis=1), 1, atol=1e-4),
                 'invalid source normals')
        _require(np.abs(positions).max() <= MAX_EXTENT, 'source geometry exceeds extent limit')
        uv = None if kind == 'base' else _accessor(doc, binary, attrs['TEXCOORD_0'], 2)
        _require(uv is None or len(uv) == len(positions), 'UV count does not match positions')
        checked.append((primitive['material'], positions, normals, uv, indices))
    return metadata, image_refs, materials, checked


def _clip(poly, axis, boundary, keep_greater):
    result = []
    before = poly[-1]
    inside_before = before[axis] >= boundary if keep_greater else before[axis] <= boundary
    for point in poly:
        inside = point[axis] >= boundary if keep_greater else point[axis] <= boundary
        if inside != inside_before:
            weight = (boundary - before[axis]) / (point[axis] - before[axis])
            intersection = before + weight * (point - before)
            intersection[axis] = boundary
            result.append(intersection)
        if inside:
            result.append(point)
        before, inside_before = point, inside
    return result


def _tiled(triangle, budget):
    """Clip positions, normals and UVs together before applying atlas coordinates."""
    low = np.floor(triangle[:, 6:8].min(axis=0)).astype(np.int64)
    high = np.maximum(low + 1, np.ceil(triangle[:, 6:8].max(axis=0)).astype(np.int64))
    cells = int(high[0] - low[0]) * int(high[1] - low[1])
    _require(cells <= MAX_CLIP_CELLS, 'triangle UV clipping exceeds cell limit')
    budget['clip_work'] += cells
    _require(budget['clip_work'] <= MAX_CLIP_WORK, 'scene UV clipping exceeds work limit')
    for v in range(int(low[1]), int(high[1])):
        row = _clip(list(triangle), 7, v, True)
        if row:
            row = _clip(row, 7, v + 1, False)
        if len(row) < 3:
            continue
        u0, u1 = math.floor(min(p[6] for p in row)), math.ceil(max(p[6] for p in row))
        for u in range(u0, max(u0 + 1, u1)):
            poly = _clip(row, 6, u, True)
            if poly:
                poly = _clip(poly, 6, u + 1, False)
            for i in range(1, len(poly) - 1):
                part = np.array([poly[0], poly[i], poly[i + 1]])
                if np.linalg.norm(np.cross(part[1, :3] - part[0, :3], part[2, :3] - part[0, :3])) <= 1e-12:
                    continue
                part[:, 6:8] = np.clip(part[:, 6:8] - [u, v], 0, 1)
                yield part


class _Writer(_Glb):
    """Reuse GLB metadata helpers, spilling the binary payload instead of retaining it."""
    def __init__(self, stack):
        super().__init__()
        self.stream = stack.enter_context(tempfile.TemporaryFile())
        self.doc.pop('extensionsUsed')
        self.doc['asset']['generator'] = '3wa GeoTexture procedural shared atlas scene v1'
        self.doc['nodes'], self.doc['meshes'], self.doc['scenes'][0]['nodes'] = [], [], []

    def view(self, data, target=None):
        self.stream.write(b'\0' * (-self.stream.tell() % 4))
        offset = self.stream.tell()
        size = len(data) if isinstance(data, (bytes, bytearray)) else data.seek(0, 2)
        _require(offset + size <= MAX_GLB_BYTES, 'scene GLB exceeds size limit')
        if isinstance(data, (bytes, bytearray)):
            self.stream.write(data)
        else:
            data.seek(0)
            shutil.copyfileobj(data, self.stream, 1024 * 1024)
        view = {'buffer': 0, 'byteOffset': offset, 'byteLength': size}
        if target:
            view['target'] = target
        self.doc['bufferViews'].append(view)
        return len(self.doc['bufferViews']) - 1

    def write(self, output):
        self.doc['buffers'] = [{'byteLength': self.stream.tell()}]
        self.stream.write(b'\0' * (-self.stream.tell() % 4))
        size = self.stream.tell()
        encoded = json.dumps(self.doc, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()
        encoded += b' ' * (-len(encoded) % 4)
        total = 28 + len(encoded) + size
        _require(total <= MAX_GLB_BYTES, 'scene GLB exceeds size limit')
        with output.open('wb') as target:
            target.write(struct.pack('<4sIIII', b'glTF', 2, total, len(encoded), 0x4e4f534a))
            target.write(encoded)
            target.write(struct.pack('<II', size, 0x004e4942))
            self.stream.seek(0)
            shutil.copyfileobj(self.stream, target, 1024 * 1024)


class _Group:
    def __init__(self, stack, textured):
        self.stream = stack.enter_context(tempfile.TemporaryFile())
        self.textured, self.count = textured, 0
        self.minimum, self.maximum = np.full(3, np.inf), np.full(3, -np.inf)
        self.pending, self.pending_count = [], 0

    def add(self, vertices, budget):
        budget['triangles'] += len(vertices) // 3
        _require(budget['triangles'] <= MAX_TRIANGLES, 'scene triangle count exceeds limit')
        vertices = vertices[:, :8 if self.textured else 6].astype('<f4')
        _require(np.isfinite(vertices).all(), 'output geometry contains nonfinite data')
        self.count += len(vertices)
        self.minimum = np.minimum(self.minimum, vertices[:, :3].min(axis=0))
        self.maximum = np.maximum(self.maximum, vertices[:, :3].max(axis=0))
        self.pending.append(vertices.tobytes())
        self.pending_count += len(vertices)
        if self.pending_count >= 12000:
            self.flush()

    def flush(self):
        self.stream.write(b''.join(self.pending))
        self.pending, self.pending_count = [], 0

    def finish(self, writer, material):
        self.flush()
        view = writer.view(self.stream, 34962)
        stride = 32 if self.textured else 24
        writer.doc['bufferViews'][view]['byteStride'] = stride
        attrs = {}
        for name, offset, kind in [('POSITION', 0, 'VEC3'), ('NORMAL', 12, 'VEC3')] + (
                [('TEXCOORD_0', 24, 'VEC2')] if self.textured else []):
            attrs[name] = len(writer.doc['accessors'])
            entry = {'bufferView': view, 'byteOffset': offset, 'componentType': 5126,
                     'count': self.count, 'type': kind}
            if name == 'POSITION':
                entry.update(min=self.minimum.tolist(), max=self.maximum.tolist())
            writer.doc['accessors'].append(entry)
        self.stream.close()
        return {'attributes': attrs, 'material': material, 'mode': 4}


def _pack(writer, image_refs, tile, progress):
    pitch = tile + GUTTER * 2
    across = ATLAS_EDGE // pitch
    refs, mapping = list(image_refs.values()), {}
    pixels = 0
    for start in range(0, len(refs), across * across):
        batch = refs[start:start + across * across]
        columns = min(across, math.ceil(math.sqrt(len(batch))))
        rows = math.ceil(len(batch) / columns)
        width, height = columns * pitch, rows * pitch
        atlas = Image.new('RGB', (width, height))
        texture = len(writer.doc.setdefault('textures', []))
        for i, (digest, path, offset, length) in enumerate(batch):
            with open(path, 'rb') as source:
                source.seek(offset)
                encoded = source.read(length)
            _require(hashlib.sha256(encoded).hexdigest() == digest, 'source image changed during compilation')
            with Image.open(io.BytesIO(encoded)) as source:
                image = source.convert('RGB').resize((tile, tile), Image.Resampling.LANCZOS)
            padded = np.pad(np.asarray(image), ((GUTTER, GUTTER), (GUTTER, GUTTER), (0, 0)), mode='wrap')
            x, y = i % columns * pitch, i // columns * pitch
            atlas.paste(Image.fromarray(padded), (x, y))
            mapping[digest] = (texture, np.array([tile / width, tile / height]),
                               np.array([(x + GUTTER) / width, (y + GUTTER) / height]))
            progress('packing', start + i + 1, len(refs))
        stream = io.BytesIO()
        atlas.save(stream, format='PNG')
        images = writer.doc.setdefault('images', [])
        images.append({'bufferView': writer.view(stream.getvalue()), 'mimeType': 'image/png'})
        writer.doc['textures'].append({'source': len(images) - 1, 'sampler': 0})
        pixels += width * height
        atlas.close()
    writer.doc['samplers'] = [{'magFilter': 9729, 'minFilter': 9729, 'wrapS': 33071, 'wrapT': 33071}]
    return mapping, pixels


def generate(request, output_path, progress=None):
    """Compile a validated request, retaining independent building nodes for exports."""
    progress = progress or (lambda phase, done, total: None)
    try:
        return _generate(request, Path(output_path).resolve(), progress)
    except (KeyError, TypeError, IndexError, AttributeError, OverflowError, struct.error) as error:
        raise ValueError('malformed canonical scene input') from error


def _generate(request, output, progress):
    lon, lat, entries = _request(request)
    tile = 128 if request['kind'] == 'preview' else 512
    k = math.pi / 180 * RADIUS_M
    kx = k * math.cos(math.radians(lat))
    image_refs, buildings, placements = {}, [], []
    minimum, maximum = np.full(3, np.inf), np.full(3, -np.inf)
    source_triangles = 0
    progress('loading', 0, len(entries))
    for i, entry in enumerate(entries):
        metadata, images, _, primitives = _load(entry)
        translation = np.array([(entry['longitude'] - lon) * kx, 0, -(entry['latitude'] - lat) * k])
        scale = np.array([math.cos(math.radians(lat)) / math.cos(math.radians(entry['latitude'])), 1, 1])
        for _, positions, _, _, indices in primitives:
            transformed = positions * scale + translation
            minimum = np.minimum(minimum, transformed.min(axis=0))
            maximum = np.maximum(maximum, transformed.max(axis=0))
            source_triangles += len(indices) // 3
        _require(source_triangles <= MAX_TRIANGLES, 'source triangle count exceeds limit')
        _require(np.max(maximum - minimum) <= MAX_EXTENT
                 and max(np.abs(minimum).max(), np.abs(maximum).max()) <= MAX_EXTENT,
                 'scene geometry exceeds 20 km extent limit')
        for image in images:
            image_refs.setdefault(image[0], image)
        building = {key: entry[key] for key in IDENTITY}
        if 'geometry_correction' in metadata:
            building['geometry_correction'] = metadata['geometry_correction']
        buildings.append(building)
        placements.append((translation, scale))
        progress('loading', i + 1, len(entries))
    budget = {'triangles': 0, 'clip_work': 0}
    with ExitStack() as stack:
        writer = _Writer(stack)
        progress('packing', 0, len(image_refs))
        mapping, pixels = _pack(writer, image_refs, tile, progress)
        shared_materials, preview_groups = {}, {}
        progress('writing', 0, len(entries))
        for i, (entry, building, (translation, scale)) in enumerate(zip(entries, buildings, placements)):
            _, images, materials, primitives = _load(entry)
            groups = preview_groups if request['kind'] == 'preview' else {}
            for source_material, positions, normals, uv, indices in primitives:
                material = copy.deepcopy(materials[source_material])
                rect = None if uv is None else mapping[images[source_material - 1][0]]
                if rect is not None:
                    material['pbrMetallicRoughness']['baseColorTexture']['index'] = rect[0]
                key = json.dumps(material, sort_keys=True)
                if key not in shared_materials:
                    shared_materials[key] = len(writer.doc['materials'])
                    writer.doc['materials'].append(material)
                material_index = shared_materials[key]
                if material_index not in groups:
                    groups[material_index] = _Group(stack, uv is not None)
                group = groups[material_index]
                vertices = np.column_stack((positions, normals, uv)) if uv is not None else np.column_stack((positions, normals))
                vertices = vertices.astype(np.float64)
                if uv is not None:
                    _require(np.abs(uv).max() <= MAX_CLIP_CELLS, 'source UV exceeds clipping limit')
                if request['kind'] == 'preview':
                    vertices[:, :3] = vertices[:, :3] * scale + translation
                    vertices[:, 3:6] /= scale
                    vertices[:, 3:6] /= np.linalg.norm(vertices[:, 3:6], axis=1)[:, None]
                for triangle in indices.reshape(-1, 3):
                    parts = [vertices[triangle]] if uv is None else _tiled(vertices[triangle], budget)
                    for part in parts:
                        if rect is not None:
                            part[:, 6:8] = part[:, 6:8] * rect[1] + rect[2]
                        part[:, 3:6] /= np.linalg.norm(part[:, 3:6], axis=1)[:, None]
                        group.add(part, budget)
            if request['kind'] == 'export':
                mesh = {'name': entry['build_id'], 'primitives': [group.finish(writer, mat) for mat, group in groups.items()]}
                writer.doc['meshes'].append(mesh)
                writer.doc['nodes'].append({'name': entry['build_id'], 'mesh': i, 'translation': translation.tolist(),
                                             'scale': scale.tolist(), 'extras': building})
            progress('writing', i + 1, len(entries))
        if request['kind'] == 'preview':
            writer.doc['meshes'] = [{'primitives': [group.finish(writer, mat) for mat, group in preview_groups.items()]}]
            writer.doc['nodes'] = [{'mesh': 0}]
        writer.doc['scenes'][0]['nodes'] = list(range(len(writer.doc['nodes'])))
        metadata = {key: request[key] for key in ('version', 'scene_id', 'kind', 'recipe_key')}
        metadata.update({'appearance_kind': 'procedural', 'verification_status': 'synthetic', 'longitude': lon,
                         'latitude': lat, 'altitude': 0, 'heading': 0, 'scale': 1, 'up_axis': 'Y', 'forward_axis': '-Z',
                         'members': [entry['asset_id'] for entry in entries], 'buildings': buildings,
                         'stats': {'buildings': len(entries), 'triangles': budget['triangles'],
                                   'primitives': sum(len(mesh['primitives']) for mesh in writer.doc['meshes']),
                                   'textures': len(writer.doc['textures']), 'texture_pixels': pixels,
                                   'atlas_tile_size': tile}})
        writer.doc['asset']['extras'] = {'geotexture': metadata}
        missing, folder = [], output.parent
        while not folder.exists():
            missing.append(folder)
            folder = folder.parent
        for folder in reversed(missing):
            folder.mkdir(mode=0o755)
            folder.chmod(0o755)
        writer.write(output)
    digest = hashlib.sha256()
    with output.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    manifest = {**metadata, 'model_sha256': digest.hexdigest()}
    manifest_path = output.with_suffix('.manifest.json')
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2, allow_nan=False) + '\n', encoding='utf-8')
    return {'model_path': str(output), 'manifest_path': str(manifest_path), **manifest}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input_path', type=Path)
    parser.add_argument('output_path', type=Path)
    args = parser.parse_args(argv)
    try:
        _require(args.input_path.stat().st_size <= MAX_INPUT_BYTES, 'scene input exceeds size limit')
        request = json.loads(args.input_path.read_text(encoding='utf-8-sig'))
        generate(request, args.output_path,
                 lambda phase, done, total: print(json.dumps({'phase': phase, 'done': done, 'total': total}), flush=True))
    except (ValueError, OSError) as error:
        parser.exit(1, f'Scene compilation failed: {error}\n')


if __name__ == '__main__':
    main()
