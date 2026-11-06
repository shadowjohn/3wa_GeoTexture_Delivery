"""Shared atlas pixels, tiled geometry, independent export nodes and input bounds."""
import copy
import hashlib
import io
import json
import math
import struct
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image

from test_procedural import ROOT, accessor, parse_glb, procedural, ring
from pipeline import scene


def output_accessor(doc, binary, index):
    item = doc['accessors'][index]
    view = doc['bufferViews'][item['bufferView']]
    columns = {'VEC2': 2, 'VEC3': 3}[item['type']]
    return np.ndarray((item['count'], columns), dtype='<f4', buffer=binary,
                      offset=view.get('byteOffset', 0) + item.get('byteOffset', 0),
                      strides=(view.get('byteStride', columns * 4), 4))


def image_bytes(doc, binary, index):
    view = doc['bufferViews'][doc['images'][index]['bufferView']]
    return binary[view.get('byteOffset', 0):view.get('byteOffset', 0) + view['byteLength']]


def write_glb(path, doc, binary):
    doc['buffers'] = [{'byteLength': len(binary)}]
    text = json.dumps(doc, separators=(',', ':')).encode()
    text += b' ' * (-len(text) % 4)
    binary += b'\0' * (-len(binary) % 4)
    path.write_bytes(struct.pack('<4sIIII', b'glTF', 2, 28 + len(text) + len(binary), len(text), 0x4e4f534a)
                     + text + struct.pack('<II', len(binary), 0x004e4942) + binary)


class SceneTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        entries = []
        for i in range(2):
            source = {'source_key': 'fixture-source', 'build_id': f'building-{i}', 'source_hash': 'a' * 64,
                      'recipe_key': 'procedural-tw-v1',
                      'feature': {'type': 'Feature', 'properties': {'height': 14.4},
                                  'geometry': {'type': 'Polygon', 'coordinates': [ring([
                                      (i * 45, i * 30), (i * 45 + 20, i * 30),
                                      (i * 45 + 20, i * 30 + 14), (i * 45, i * 30 + 14)])]}}}
            path = self.folder / f'source-{i}.glb'
            result = procedural.generate_request(source, path)
            entries.append({key: result[key] for key in scene.IDENTITY if key != 'asset_id'})
            entries[-1].update(asset_id=f'{i + 1:032x}', path=str(path), sha256=result['model_sha256'])
        self.request = {'version': 1, 'scene_id': 'b' * 64, 'kind': 'preview', 'recipe_key': 'procedural-tw-v1',
                        'origin': {'longitude': 121.418, 'latitude': 25.175}, 'entries': entries}

    def generate(self):
        output = self.folder / f'{self.request["kind"]}.glb'
        result = scene.generate(self.request, output)
        doc, binary = parse_glb(output)
        return output, result, doc, binary

    def mutate(self, change, index=0):
        entry = self.request['entries'][index]
        path = Path(entry['path'])
        doc, binary = parse_glb(path)
        binary = bytearray(binary[:doc['buffers'][0]['byteLength']])
        change(doc, binary)
        write_glb(path, doc, binary)
        entry['sha256'] = hashlib.sha256(path.read_bytes()).hexdigest()

    def test_preview_embeds_shared_atlas_with_repeat_gutters_and_complete_metadata(self):
        output, result, doc, binary = self.generate()
        self.assertEqual(result['model_sha256'], hashlib.sha256(output.read_bytes()).hexdigest())
        self.assertEqual(result['members'], [entry['asset_id'] for entry in self.request['entries']])
        self.assertEqual(result['stats']['buildings'], 2)
        self.assertEqual(result['stats']['primitives'], 3)
        self.assertEqual(result['stats']['textures'], 1)
        self.assertEqual(result['stats']['atlas_tile_size'], 128)
        self.assertEqual(result['verification_status'], 'synthetic')
        self.assertEqual(len(doc['nodes']), 1)
        self.assertEqual(len(doc['materials']), 3)
        self.assertEqual(doc['samplers'], [{'magFilter': 9729, 'minFilter': 9729, 'wrapS': 33071, 'wrapT': 33071}])
        self.assertEqual(doc['asset']['extras']['geotexture']['buildings'], result['buildings'])
        manifest = json.loads(output.with_suffix('.manifest.json').read_text())
        self.assertEqual(manifest, {key: value for key, value in result.items() if key not in ('model_path', 'manifest_path')})
        atlas = np.asarray(Image.open(io.BytesIO(image_bytes(doc, binary, 0))))
        self.assertEqual(atlas.shape, (264, 264, 3))
        self.assertEqual(result['stats']['texture_pixels'], 264 * 264)
        for i, entry in enumerate(self.request['entries']):
            source_doc, source_binary = parse_glb(entry['path'])
            for j in range(2):
                source = Image.open(io.BytesIO(image_bytes(source_doc, source_binary, j)))
                expected = np.pad(np.asarray(source.resize((128, 128), Image.Resampling.LANCZOS)),
                                  ((2, 2), (2, 2), (0, 0)), mode='wrap')
                slot = i * 2 + j
                x, y = slot % 2 * 132, slot // 2 * 132
                np.testing.assert_array_equal(atlas[y:y + 132, x:x + 132], expected)
        triangles = 0
        for primitive in doc['meshes'][0]['primitives']:
            attrs = primitive['attributes']
            positions = output_accessor(doc, binary, attrs['POSITION'])
            normals = output_accessor(doc, binary, attrs['NORMAL'])
            triangles += len(positions) // 3
            np.testing.assert_allclose(np.linalg.norm(normals, axis=1), 1, atol=1e-6)
            self.assertTrue(np.isfinite(positions).all())
            if 'TEXCOORD_0' in attrs:
                uv = output_accessor(doc, binary, attrs['TEXCOORD_0'])
                self.assertGreaterEqual(uv.min(), 0)
                self.assertLessEqual(uv.max(), 1)
        self.assertEqual(triangles, result['stats']['triangles'])
        self.assertGreater(triangles, 24)

    def test_export_keeps_separate_local_meshes_ids_and_geometry_correction(self):
        correction = {'kind': 'station-roof-v1', 'source': 'official fixture', 'height_m': 14.4}
        self.mutate(lambda doc, binary: doc['asset']['extras']['geotexture'].update(geometry_correction=correction))
        self.request['kind'] = 'export'
        _, result, doc, binary = self.generate()
        self.assertEqual(len(doc['nodes']), 2)
        self.assertEqual(len(doc['meshes']), 2)
        self.assertEqual(result['stats']['primitives'], 6)
        self.assertEqual(result['stats']['atlas_tile_size'], 512)
        self.assertEqual(len(doc['images']), 1)
        self.assertEqual(doc['nodes'][0]['extras']['geometry_correction'], correction)
        self.assertEqual(result['buildings'][0]['geometry_correction'], correction)
        for i, node in enumerate(doc['nodes']):
            entry = self.request['entries'][i]
            self.assertEqual(node['mesh'], i)
            for key in scene.IDENTITY:
                self.assertEqual(node['extras'][key], entry[key])
            expected_scale = math.cos(math.radians(25.175)) / math.cos(math.radians(entry['latitude']))
            self.assertAlmostEqual(node['scale'][0], expected_scale)
            self.assertGreater(node['translation'][0], 0)
            self.assertLess(node['translation'][2], 0)
            # Clipping may subdivide triangles but must preserve source surface area.
            source_doc, source_binary = parse_glb(entry['path'])
            source_area = 0
            for primitive in source_doc['meshes'][0]['primitives']:
                points = accessor(source_doc, source_binary, primitive['attributes']['POSITION'])
                ids = accessor(source_doc, source_binary, primitive['indices']).reshape(-1, 3)
                faces = points[ids].astype(float)
                source_area += np.linalg.norm(np.cross(faces[:, 1] - faces[:, 0], faces[:, 2] - faces[:, 0]), axis=1).sum() / 2
            output_area = 0
            for primitive in doc['meshes'][i]['primitives']:
                positions = output_accessor(doc, binary, primitive['attributes']['POSITION'])
                self.assertLess(np.abs(positions[:, [0, 2]]).max(), 11)
                faces = positions.reshape(-1, 3, 3).astype(float)
                output_area += np.linalg.norm(np.cross(faces[:, 1] - faces[:, 0], faces[:, 2] - faces[:, 0]), axis=1).sum() / 2
            self.assertAlmostEqual(source_area, output_area, places=3)

    def test_integer_uv_clipping_preserves_negative_tiles_and_interpolation(self):
        triangle = np.array([[0, 0, 0, 0, 0, 1, -.5, -1.5],
                             [3, 0, 0, 0, 0, 1, 2.5, -1.5],
                             [0, 3, 0, 0, 0, 1, -.5, 1.5]])
        parts = list(scene._tiled(triangle, {'clip_work': 0}))
        self.assertGreater(len(parts), 6)
        total = 0
        for part in parts:
            self.assertGreaterEqual(part[:, 6:8].min(), 0)
            self.assertLessEqual(part[:, 6:8].max(), 1)
            original_uv = part[:, :2] + [-.5, -1.5]
            np.testing.assert_allclose(original_uv - part[:, 6:8],
                                       np.rint(original_uv - part[:, 6:8]), atol=1e-12)
            total += np.linalg.norm(np.cross(part[1, :3] - part[0, :3], part[2, :3] - part[0, :3])) / 2
        self.assertAlmostEqual(total, 4.5)

    def test_legacy_per_surface_primitives_compile_identically(self):
        output, _, _, _ = self.generate()
        before = output.read_bytes()
        def legacy(doc, binary):
            primitives = []
            for primitive in doc['meshes'][0]['primitives']:
                for span in primitive['extras']['surface_ranges']:
                    item = copy.deepcopy(primitive)
                    original = doc['accessors'][primitive['indices']]
                    doc['accessors'].append({**original, 'byteOffset': span['index_offset'] * 4,
                                             'count': span['index_count']})
                    item['indices'] = len(doc['accessors']) - 1
                    item['extras'].pop('surface_ranges')
                    item['extras']['key'] = span['key']
                    primitives.append(item)
            doc['meshes'][0]['primitives'] = primitives
        for i in range(2):
            self.mutate(legacy, i)
        after, _, _, _ = self.generate()
        self.assertEqual(before, after.read_bytes())

    def test_identical_embedded_images_are_deduplicated(self):
        first_doc, first_binary = parse_glb(self.request['entries'][0]['path'])
        images = [image_bytes(first_doc, first_binary, i) for i in range(2)]
        def same_images(doc, binary):
            for i, content in enumerate(images):
                binary.extend(b'\0' * (-len(binary) % 4))
                doc['bufferViews'][doc['images'][i]['bufferView']] = {
                    'buffer': 0, 'byteOffset': len(binary), 'byteLength': len(content)}
                binary.extend(content)
                doc['asset']['extras']['geotexture']['textures'][i]['embedded_sha256'] = hashlib.sha256(content).hexdigest()
        self.mutate(same_images, 1)
        _, result, doc, binary = self.generate()
        self.assertEqual(result['stats']['texture_pixels'], 264 * 132)
        self.assertEqual(Image.open(io.BytesIO(image_bytes(doc, binary, 0))).size, (264, 132))

    def test_atlas_overflow_uses_separate_clamped_images_and_compatible_materials(self):
        with patch.object(scene, 'ATLAS_EDGE', 132):
            _, result, doc, binary = self.generate()
        self.assertEqual(result['stats']['textures'], 4)
        self.assertEqual(result['stats']['primitives'], 5)
        for i in range(4):
            self.assertEqual(Image.open(io.BytesIO(image_bytes(doc, binary, i))).size, (132, 132))

    def test_preview_projection_and_inverse_normal_scale_for_each_recipe(self):
        entry = self.request['entries'][0]
        self.request['entries'] = [entry]
        for recipe in procedural.RECIPES:
            with self.subTest(recipe=recipe):
                request = {'source_key': entry['source_key'], 'build_id': entry['build_id'],
                           'source_hash': entry['source_hash'], 'recipe_key': recipe,
                           'feature': {'type': 'Feature', 'properties': {'height': 14.4},
                                       'geometry': {'type': 'Polygon', 'coordinates': [ring([
                                           (0, 10000), (10, 10010), (0, 10020), (-10, 10010)])]}}}
                source = procedural.generate_request(request, entry['path'])
                entry.update({key: source[key] for key in scene.IDENTITY if key != 'asset_id'})
                entry['sha256'] = source['model_sha256']
                self.request['recipe_key'] = recipe
                source_doc, source_binary = parse_glb(entry['path'])
                wall = source_doc['meshes'][0]['primitives'][0]
                positions = accessor(source_doc, source_binary, wall['attributes']['POSITION']).astype(float)
                normals = accessor(source_doc, source_binary, wall['attributes']['NORMAL']).astype(float)
                scale = math.cos(math.radians(25.175)) / math.cos(math.radians(entry['latitude']))
                normals[:, 0] /= scale
                normals /= np.linalg.norm(normals, axis=1)[:, None]
                expected_normals = np.unique(np.round(normals, 6), axis=0)
                _, _, doc, binary = self.generate()
                wall = doc['meshes'][0]['primitives'][0]
                actual_normals = output_accessor(doc, binary, wall['attributes']['NORMAL'])
                np.testing.assert_allclose(np.unique(np.round(actual_normals.astype(float), 6), axis=0), expected_normals)
                actual_positions = output_accessor(doc, binary, wall['attributes']['POSITION'])
                k = math.pi / 180 * scene.RADIUS_M
                translation = [(entry['longitude'] - 121.418) * k * math.cos(math.radians(25.175)),
                               0, -(entry['latitude'] - 25.175) * k]
                positions = positions * [scale, 1, 1] + translation
                np.testing.assert_allclose(actual_positions.min(axis=0), positions.min(axis=0), atol=.001)
                np.testing.assert_allclose(actual_positions.max(axis=0), positions.max(axis=0), atol=.001)

    def test_rejects_tampering_external_resources_transforms_and_bad_accessors(self):
        entry = self.request['entries'][0]
        path = Path(entry['path'])
        original, digest = path.read_bytes(), entry['sha256']
        mutations = [
            ('metadata', lambda d, b: d['asset']['extras']['geotexture'].update(build_id='wrong')),
            ('transforms', lambda d, b: d['nodes'][0].update(translation=[1, 2, 3])),
            ('image', lambda d, b: d['images'][0].update(uri='https://example.test/image.png')),
            ('bounds', lambda d, b: d['accessors'][0].update(count=2000000)),
            ('bounds', lambda d, b: d['accessors'][0].update(min=[-99, -99, -99])),
            ('bounds', lambda d, b: struct.pack_into('<f', b, d['bufferViews'][d['accessors'][1]['bufferView']]['byteOffset'], 99)),
            ('nonfinite', lambda d, b: struct.pack_into('<f', b, d['bufferViews'][d['accessors'][0]['bufferView']]['byteOffset'], float('nan'))),
            ('index', lambda d, b: struct.pack_into('<I', b, d['bufferViews'][d['accessors'][3]['bufferView']]['byteOffset'], 999999)),
        ]
        for expected, change in mutations:
            with self.subTest(expected=expected):
                path.write_bytes(original)
                self.mutate(change)
                with self.assertRaisesRegex(ValueError, expected):
                    self.generate()
        path.write_bytes(original + b'corrupt')
        entry['sha256'] = digest
        with self.assertRaisesRegex(ValueError, 'SHA256'):
            self.generate()
        self.assertFalse((self.folder / 'preview.glb').exists())

    def test_limits_reject_buildings_extent_triangles_size_and_clip_explosion(self):
        original = copy.deepcopy(self.request)
        self.request['entries'] *= 1001
        with self.assertRaisesRegex(ValueError, 'building count'):
            self.generate()
        self.request = copy.deepcopy(original)
        self.request['entries'].append(self.request['entries'][0])
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            self.generate()
        self.request = copy.deepcopy(original)
        self.request['origin']['longitude'] += 1
        with self.assertRaisesRegex(ValueError, 'extent'):
            self.generate()
        self.request = copy.deepcopy(original)
        with patch.object(scene, 'MAX_TRIANGLES', 25):
            with self.assertRaisesRegex(ValueError, 'triangle count'):
                self.generate()
        with patch.object(scene, 'MAX_GLB_BYTES', 1024):
            with self.assertRaisesRegex(ValueError, 'size limit'):
                self.generate()
        with patch.object(scene, 'MAX_CLIP_CELLS', 2):
            with self.assertRaisesRegex(ValueError, 'clipping'):
                self.generate()

    def test_cli_reports_flushed_phase_progress_and_errors(self):
        request = self.folder / 'scene.json'
        output = self.folder / 'nested' / 'scene.glb'
        request.write_text(json.dumps(self.request))
        result = subprocess.run([sys.executable, '-m', 'pipeline.scene', str(request), str(output)],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        progress = [json.loads(line) for line in result.stdout.splitlines()]
        self.assertEqual({p['phase'] for p in progress}, {'loading', 'packing', 'writing'})
        self.assertEqual(progress[-1], {'phase': 'writing', 'done': 2, 'total': 2})
        self.assertTrue(output.is_file())
        self.assertTrue(output.with_suffix('.manifest.json').is_file())
        self.assertEqual(output.parent.stat().st_mode & 0o777, 0o755)
        self.request['scene_id'] = 'invalid'
        request.write_text(json.dumps(self.request))
        failed = subprocess.run([sys.executable, '-m', 'pipeline.scene', str(request), str(output)],
                                cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertNotEqual(failed.returncode, 0)
        self.assertIn('scene_id', failed.stderr)


if __name__ == '__main__':
    unittest.main()
