"""Procedural appearance is synthetic; geometry remains the supplied footprint/height."""
import copy
import hashlib
import importlib.util
import io
import json
import math
import struct
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np
from PIL import Image
from shapely.geometry import MultiPolygon, Point, Polygon
from shapely.ops import unary_union


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
procedural = None
if (ROOT / 'pipeline' / 'procedural.py').is_file():
    from pipeline import procedural


def ring(points):
    k = math.pi / 180 * 6371000
    return [[121.418 + x / (k * math.cos(math.radians(25.175))), 25.175 + y / k]
            for x, y in points + [points[0]]]


def parse_glb(path):
    raw = Path(path).read_bytes()
    assert struct.unpack_from('<4sII', raw) == (b'glTF', 2, len(raw))
    length, kind = struct.unpack_from('<II', raw, 12)
    assert kind == 0x4e4f534a
    doc = json.loads(raw[20:20 + length])
    pos = 20 + length
    length, kind = struct.unpack_from('<II', raw, pos)
    assert kind == 0x004e4942
    return doc, raw[pos + 8:pos + 8 + length]


def accessor(doc, binary, index):
    a = doc['accessors'][index]
    view = doc['bufferViews'][a['bufferView']]
    columns = {'SCALAR': 1, 'VEC2': 2, 'VEC3': 3}[a['type']]
    return np.frombuffer(binary, dtype={5126: '<f4', 5125: '<u4'}[a['componentType']],
                         count=a['count'] * columns,
                         offset=view.get('byteOffset', 0) + a.get('byteOffset', 0)).reshape(-1, columns)


class ProceduralTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(procedural, 'procedural facade generator is not implemented')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.request = {'source_key': 'new-taipei-8418', 'build_id': 'fixture',
                        'source_hash': 'a' * 64, 'recipe_key': 'procedural-tw-v1',
                        'feature': {'type': 'Feature', 'properties': {'height': 14.4},
                                    'geometry': {'type': 'Polygon', 'coordinates': [
                                        ring([(0, 0), (20, 0), (20, 14), (0, 14)])]}}}

    def generate(self, name='building'):
        source = self.folder / 'input.json'
        source.write_text(json.dumps(self.request), encoding='utf-8')
        output = self.folder / (name + '.glb')
        result = procedural.generate(source, output)
        doc, binary = parse_glb(output)
        manifest = json.loads(output.with_suffix('.manifest.json').read_text(encoding='utf-8'))
        return output, result, doc, binary, manifest

    def footprint(self, manifest):
        k = math.pi / 180 * 6371000
        kx = k * math.cos(math.radians(manifest['latitude']))
        geometry = self.request['feature']['geometry']
        polygons = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
        projected = []
        for rings in polygons:
            coords = [[((p[0] - manifest['longitude']) * kx,
                        (p[1] - manifest['latitude']) * k) for p in r] for r in rings]
            projected.append(Polygon(coords[0], coords[1:]))
        return MultiPolygon(projected)

    def test_manifest_has_synthetic_identity_complete_surfaces_and_embedded_images(self):
        output, result, doc, binary, manifest = self.generate()
        self.assertEqual(result['model_path'], str(output))
        for key in ('source_key', 'build_id', 'source_hash', 'recipe_key'):
            self.assertEqual(manifest[key], self.request[key])
        self.assertEqual(manifest['version'], 1)
        self.assertEqual(manifest['style_key'], self.request['recipe_key'])
        self.assertEqual(manifest['appearance_kind'], 'procedural')
        self.assertEqual(manifest['verification_status'], 'synthetic')
        self.assertEqual(manifest['texture_status'], 'complete')
        self.assertEqual(manifest['walls_total'], 4)
        self.assertEqual(manifest['walls_textured'], 4)
        self.assertEqual(manifest['surfaces_total'], 5)
        self.assertEqual(manifest['surfaces_textured'], 5)
        self.assertEqual(manifest['model_sha256'], hashlib.sha256(output.read_bytes()).hexdigest())
        self.assertEqual((manifest['up_axis'], manifest['forward_axis']), ('Y', '-Z'))
        self.assertEqual((manifest['altitude'], manifest['heading'], manifest['scale']), (0, 0, 1))
        coords = self.request['feature']['geometry']['coordinates'][0]
        for i, field in enumerate(('longitude', 'latitude')):
            self.assertAlmostEqual(manifest[field], (min(p[i] for p in coords) + max(p[i] for p in coords)) / 2)
        for surface in manifest['surfaces']:
            self.assertTrue(surface['textured'])
            self.assertGreater(surface['area_m2'], 0)
            self.assertEqual((surface['coverage'], surface['geometric_coverage']), (1, 1))
        embedded = doc['asset']['extras']['geotexture']
        for key in ('source_key', 'build_id', 'source_hash', 'recipe_key', 'style_key', 'appearance_kind',
                    'verification_status', 'surfaces', 'textures', 'height_m'):
            self.assertEqual(embedded[key], manifest[key])
        for forbidden in ('observed_share', 'observed_wall_area_m2', 'tier', 'upstream_wall_keys', 'imagery_hash'):
            self.assertNotIn('"' + forbidden + '"', json.dumps(manifest))
        self.assertEqual(len(doc['images']), 2)
        self.assertEqual(len(manifest['textures']), 2)
        for image, texture in zip(doc['images'], manifest['textures']):
            self.assertNotIn('uri', image)
            view = doc['bufferViews'][image['bufferView']]
            raw = binary[view['byteOffset']:view['byteOffset'] + view['byteLength']]
            self.assertEqual(hashlib.sha256(raw).hexdigest(), texture['embedded_sha256'])
            decoded = Image.open(io.BytesIO(raw))
            self.assertEqual(decoded.size, (texture['width'], texture['height']))
            self.assertGreaterEqual(min(decoded.size), 256)
            self.assertGreater(float(np.asarray(decoded).std()), 8)

    def test_geometry_uses_true_height_outward_normals_and_meter_scaled_repeat(self):
        _, _, doc, binary, manifest = self.generate()
        footprint = self.footprint(manifest)
        walls, wall_materials = [], set()
        self.assertNotIn('KHR_materials_unlit', doc.get('extensionsUsed', []))
        for material in doc['materials']:
            self.assertNotIn('KHR_materials_unlit', material.get('extensions', {}))
        for primitive in doc['meshes'][0]['primitives']:
            extra = primitive['extras']
            positions = accessor(doc, binary, primitive['attributes']['POSITION'])
            normals = accessor(doc, binary, primitive['attributes']['NORMAL'])
            indices = accessor(doc, binary, primitive['indices']).reshape(-1, 3)
            np.testing.assert_allclose(np.linalg.norm(normals, axis=1), 1, atol=1e-6)
            for triangle in positions[indices]:
                n = np.cross(triangle[1] - triangle[0], triangle[2] - triangle[0])
                n /= np.linalg.norm(n)
                self.assertGreater(float(np.dot(n, normals[indices[0, 0]])), .999)
            if extra['kind'] == 'base':
                self.assertFalse(extra['textured'])
                self.assertFalse(extra['required_texture'])
                np.testing.assert_allclose(positions[:, 1], 0)
                continue
            self.assertTrue(extra['textured'])
            self.assertTrue(extra['required_texture'])
            material = doc['materials'][primitive['material']]
            texture = doc['textures'][material['pbrMetallicRoughness']['baseColorTexture']['index']]
            sampler = doc['samplers'][texture['sampler']]
            self.assertEqual((sampler['wrapS'], sampler['wrapT']), (10497, 10497))
            if extra['kind'] == 'roof':
                np.testing.assert_allclose(positions[:, 1], 14.4, atol=1e-6)
                continue
            walls.append(extra['wall_key'])
            wall_materials.add(primitive['material'])
            self.assertEqual(float(positions[:, 1].min()), 0)
            self.assertAlmostEqual(float(positions[:, 1].max()), 14.4, places=5)
            uv = accessor(doc, binary, primitive['attributes']['TEXCOORD_0'])
            width = float(np.linalg.norm(positions[1] - positions[0]))
            self.assertGreaterEqual(width / float(np.ptp(uv[:, 0])), 4)
            self.assertLessEqual(width / float(np.ptp(uv[:, 0])), 9)
            self.assertGreaterEqual(14.4 / float(np.ptp(uv[:, 1])), 5)
            self.assertLessEqual(14.4 / float(np.ptp(uv[:, 1])), 8)
            center = positions.mean(axis=0)
            outside = center + normals[0] * .05
            inside = center - normals[0] * .05
            self.assertFalse(footprint.contains(Point(outside[0], -outside[2])))
            self.assertTrue(footprint.contains(Point(inside[0], -inside[2])))
        self.assertEqual(len(walls), len(set(walls)))
        self.assertEqual(len(wall_materials), 1)

    def test_concave_courtyard_and_multipolygon_roofs_exactly_cover_footprint(self):
        # Concavity, one courtyard and a second disjoint polygon must all survive.
        polygons = [[ring([(0, 0), (30, 0), (30, 10), (20, 10), (20, 30), (0, 30)]),
                     ring([(4, 4), (4, 12), (12, 12), (12, 4)])],
                    [ring([(40, 0), (52, 0), (52, 8), (40, 8)])]]
        for geometry in ({'type': 'Polygon', 'coordinates': polygons[0]},
                         {'type': 'MultiPolygon', 'coordinates': polygons},
                         {'type': 'MultiPolygon', 'coordinates': [[r[::-1] for r in p] for p in polygons]}):
            with self.subTest(kind=geometry['type']):
                self.request['feature']['geometry'] = geometry
                _, _, doc, binary, manifest = self.generate()
                footprint = self.footprint(manifest)
                roofs = []
                for primitive in doc['meshes'][0]['primitives']:
                    if primitive['extras']['kind'] != 'roof':
                        continue
                    positions = accessor(doc, binary, primitive['attributes']['POSITION'])
                    indices = accessor(doc, binary, primitive['indices']).reshape(-1, 3)
                    roofs.extend(Polygon([(float(p[0]), -float(p[2])) for p in t]) for t in positions[indices])
                roof = unary_union(roofs)
                self.assertLess(roof.symmetric_difference(footprint).area, 0.001)
                self.assertAlmostEqual(sum(p.area for p in roofs), footprint.area, places=3)
                expected_walls = sum(len(r) - 1 for p in (polygons if geometry['type'] == 'MultiPolygon' else [polygons[0]]) for r in p)
                self.assertEqual(manifest['walls_total'], expected_walls)
                self.assertEqual(manifest['surfaces_textured'], manifest['surfaces_total'])

    def test_deterministic_bytes_and_distinct_styles_and_building_seed(self):
        first, _, _, _, first_manifest = self.generate('first')
        second, _, _, _, second_manifest = self.generate('second')
        self.assertEqual(first.read_bytes(), second.read_bytes())
        self.assertEqual(first_manifest, second_manifest)
        digests = set()
        for recipe in ('procedural-tw-v1', 'procedural-modern-v1', 'procedural-pastel-v1'):
            self.request['recipe_key'] = recipe
            _, _, _, _, manifest = self.generate(recipe)
            digests.add(manifest['textures'][0]['embedded_sha256'])
        self.assertEqual(len(digests), 3)
        self.request['recipe_key'] = 'procedural-tw-v1'
        self.request['build_id'] = 'different-building'
        _, _, _, _, manifest = self.generate()
        self.assertNotEqual(first_manifest['textures'][0]['embedded_sha256'], manifest['textures'][0]['embedded_sha256'])

    def test_rejects_missing_nonpositive_and_nonfinite_heights(self):
        for height in (None, 0, -2, True, 'not-a-number', float('nan'), float('inf'), [], {}):
            with self.subTest(height=height):
                self.request['feature']['properties']['height'] = height
                with self.assertRaisesRegex(ValueError, 'height'):
                    self.generate()
        self.request['feature']['properties'] = {}
        with self.assertRaisesRegex(ValueError, 'height'):
            self.generate()

    def test_rejects_unknown_recipes_and_malformed_geometry_before_writing(self):
        self.request['recipe_key'] = 'photo-v1'
        with self.assertRaisesRegex(ValueError, 'recipe'):
            self.generate()
        self.request['recipe_key'] = 'procedural-tw-v1'
        valid = copy.deepcopy(self.request['feature']['geometry'])
        invalid = [None, {}, {'type': 'Point', 'coordinates': [121, 25]},
                   {'type': 'Polygon', 'coordinates': []},
                   {'type': 'Polygon', 'coordinates': [[[121, 25], [122, 25], [122, 26]]]},
                   {'type': 'Polygon', 'coordinates': [ring([(0, 0), (10, 10), (0, 10), (10, 0)])]},
                   {'type': 'Polygon', 'coordinates': [ring([(0, 0), (0, 0), (10, 10), (10, 0)])]},
                   {'type': 'Polygon', 'coordinates': [[[181, 25], [181.1, 25], [181.1, 26], [181, 25]]]},
                   {'type': 'MultiPolygon', 'coordinates': [valid['coordinates'], valid['coordinates']]}]
        for geometry in invalid:
            with self.subTest(geometry=geometry):
                self.request['feature']['geometry'] = geometry
                with self.assertRaisesRegex(ValueError, 'geometry|footprint|ring|coordinate'):
                    self.generate()
        self.assertFalse((self.folder / 'building.glb').exists())

    def test_cli_creates_model_and_manifest_and_reports_paths(self):
        source = self.folder / 'input.json'
        source.write_text(json.dumps(self.request), encoding='utf-8')
        output = self.folder / 'nested' / 'building.glb'
        result = subprocess.run([sys.executable, '-m', 'pipeline.procedural', str(source), str(output)],
                                cwd=ROOT, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual(report['model_path'], str(output))
        self.assertEqual(report['texture_status'], 'complete')
        self.assertTrue(output.with_suffix('.manifest.json').is_file())
        self.assertEqual(output.parent.stat().st_mode & 0o777, 0o755)


if __name__ == '__main__':
    unittest.main()
