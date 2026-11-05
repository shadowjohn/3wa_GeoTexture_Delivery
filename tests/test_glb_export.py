"""合成圖像只驗證匯出幾何／材質契約，不作真實建物成果。"""
import hashlib
import importlib.util
import io
import json
import math
import struct
import tempfile
import unittest
from collections import Counter
from pathlib import Path

import numpy as np
from PIL import Image
from shapely.geometry import Polygon


SCRIPT = Path(__file__).resolve().parents[1] / 'pipeline' / 'glb_export.py'
exporter = None
if SCRIPT.is_file():
    spec = importlib.util.spec_from_file_location('glb_export', SCRIPT)
    exporter = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(exporter)


def lonlat(x, y):
    k = math.pi / 180 * 6371000
    return [121.418 + x / (k * math.cos(math.radians(25.175))), 25.175 + y / k]


def ring(points):
    return [lonlat(*p) for p in points + [points[0]]]


def parse_glb(path):
    raw = Path(path).read_bytes()
    magic, version, size = struct.unpack_from('<4sII', raw)
    if (magic, version, size) != (b'glTF', 2, len(raw)):
        raise AssertionError('invalid GLB header')
    length, kind = struct.unpack_from('<II', raw, 12)
    assert kind == 0x4e4f534a
    doc = json.loads(raw[20:20 + length])
    pos = 20 + length
    length, kind = struct.unpack_from('<II', raw, pos)
    assert kind == 0x004e4942
    binary = raw[pos + 8:pos + 8 + length]
    return doc, binary


def accessor(doc, binary, index):
    a = doc['accessors'][index]
    v = doc['bufferViews'][a['bufferView']]
    dtype = {5126: '<f4', 5125: '<u4', 5123: '<u2'}[a['componentType']]
    columns = {'SCALAR': 1, 'VEC2': 2, 'VEC3': 3}[a['type']]
    return np.frombuffer(binary, dtype=dtype, count=a['count'] * columns,
                         offset=v.get('byteOffset', 0) + a.get('byteOffset', 0)).reshape(-1, columns)


class GlbExportTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(exporter, 'GLB exporter 尚未實作')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        square = [(0, 0), (4, 0), (4, 3), (0, 3)]
        self.pilot = {
            'version': 1, 'source_key': 'new-taipei-8418', 'build_id': 'fixture',
            'source_hash': 'a' * 64, 'recipe_key': 'texture-v1', 'engine_commit': 'b' * 40,
            'imagery_hash': 'c' * 64,
            'feature': {'type': 'Feature', 'geometry': {'type': 'Polygon', 'coordinates': [ring(square)]},
                        'properties': {'height': 5}},
            'origin': {'longitude': 121.418, 'latitude': 25.175},
            'walls': [{'key': 'south', 'a_lonlat': lonlat(0, 0), 'b_lonlat': lonlat(4, 0),
                       'height_m': 5, 'length_m': 4, 'status': 'textured', 'tier': 'A',
                       'observed_share': .75, 'extent': {'s_l': 0, 's_r': 4, 'h_bot': 0, 'h_top': 5},
                       'tex_path': None, 'views': []}],
            'attribution': [],
        }

    def texture(self, rgba=None):
        path = self.folder / 'fixture_tex.png'
        pixels = rgba or [(255, 0, 0, 255), (0, 255, 0, 255),
                          (0, 0, 255, 255), (255, 255, 0, 255)]
        image = Image.new('RGBA', (2, 2))
        image.putdata(pixels)
        image.save(path)
        self.pilot['walls'][0]['tex_path'] = str(path)
        return path

    def export(self):
        path = self.folder / 'pilot.json'
        path.write_text(json.dumps(self.pilot), encoding='utf-8')
        output = self.folder / 'building.glb'
        result = exporter.export_pilot(path, output)
        doc, binary = parse_glb(output)
        manifest = json.loads(output.with_suffix('.manifest.json').read_text(encoding='utf-8'))
        return result, doc, binary, manifest

    def test_glb_embeds_actual_image_uv_normals_indices_and_precise_partial_counts(self):
        texture = self.texture()
        result, doc, binary, manifest = self.export()
        self.assertEqual(manifest['walls_total'], 4)
        self.assertEqual(manifest['walls_textured'], 1)
        self.assertEqual(manifest['surfaces_total'], 5)
        self.assertEqual(manifest['surfaces_textured'], 1)
        self.assertEqual(manifest['texture_status'], 'partial')
        self.assertEqual(manifest['model_sha256'], hashlib.sha256((self.folder / 'building.glb').read_bytes()).hexdigest())
        self.assertEqual(manifest['up_axis'], 'Y')
        self.assertEqual(manifest['forward_axis'], '-Z')
        self.assertEqual(manifest['heading'], 0)
        self.assertEqual(result['model_path'], str(self.folder / 'building.glb'))
        self.assertNotIn('uri', doc['buffers'][0])
        self.assertTrue(doc['images'])
        for image in doc['images']:
            self.assertNotIn('uri', image)
            view = doc['bufferViews'][image['bufferView']]
            encoded = binary[view['byteOffset']:view['byteOffset'] + view['byteLength']]
            self.assertEqual(Image.open(io.BytesIO(encoded)).size, (2, 2))
        primitives = doc['meshes'][0]['primitives']
        keys = [p['extras']['wall_key'] for p in primitives if 'wall_key' in p['extras']]
        self.assertEqual(len(keys), len(set(keys)))
        for p in primitives:
            self.assertIn('NORMAL', p['attributes'])
            self.assertIn('indices', p)
            if p['extras']['textured']:
                self.assertIn('TEXCOORD_0', p['attributes'])
                material = doc['materials'][p['material']]
                self.assertIn('baseColorTexture', material['pbrMetallicRoughness'])
                self.assertIn('KHR_materials_unlit', material['extensions'])
        self.assertNotIn('extensionsRequired', doc)
        self.assertEqual(manifest['textures'][0]['source_sha256'], hashlib.sha256(texture.read_bytes()).hexdigest())

    def test_four_corner_image_retains_top_bottom_and_a_to_b_direction(self):
        self.texture()
        _, doc, binary, _ = self.export()
        p = next(p for p in doc['meshes'][0]['primitives'] if p['extras'].get('wall_key') == 'source:p0:r0:e0')
        positions = accessor(doc, binary, p['attributes']['POSITION'])
        uvs = accessor(doc, binary, p['attributes']['TEXCOORD_0'])
        expected = {(0, 0): (0, 1), (4, 0): (1, 1), (4, 5): (1, 0), (0, 5): (0, 0)}
        for pos, uv in zip(positions, uvs):
            key = (round(float(pos[0])), round(float(pos[1])))
            np.testing.assert_allclose(uv, expected[key], atol=1e-6)
        view = doc['bufferViews'][doc['images'][0]['bufferView']]
        image = Image.open(io.BytesIO(binary[view['byteOffset']:view['byteOffset'] + view['byteLength']])).convert('RGB')
        expected_colors = {(0, 5): (255, 0, 0), (4, 5): (0, 255, 0),
                           (0, 0): (0, 0, 255), (4, 0): (255, 255, 0)}
        for pos, uv in zip(positions, uvs):
            key = (round(float(pos[0])), round(float(pos[1])))
            pixel = tuple(int(round(float(c))) for c in uv)
            self.assertEqual(image.getpixel(pixel), expected_colors[key])

    def test_tex_alpha_unknown_is_opaque_white_in_embedded_image(self):
        self.texture([(255, 0, 0, 255), (0, 255, 0, 0), (0, 0, 255, 128), (255, 255, 0, 255)])
        _, doc, binary, manifest = self.export()
        view = doc['bufferViews'][doc['images'][0]['bufferView']]
        image = Image.open(io.BytesIO(binary[view['byteOffset']:view['byteOffset'] + view['byteLength']])).convert('RGBA')
        self.assertEqual(np.asarray(image).reshape(-1, 4).tolist(), [[255, 0, 0, 255], [255, 255, 255, 255],
                                                                  [127, 127, 255, 255], [255, 255, 0, 255]])
        self.assertAlmostEqual(manifest['textures'][0]['valid_pixel_share'], .75)
        self.assertAlmostEqual(manifest['textures'][0]['observed_share'], .75)

    def test_concave_polygon_hole_multipolygon_roofs_keep_footprint_and_closed_normals(self):
        outer = [(0, 0), (6, 0), (6, 2), (2, 2), (2, 6), (0, 6)]
        hole = [(.5, .5), (1.5, .5), (1.5, 1.5), (.5, 1.5)]
        second = [(10, 0), (12, 0), (12, 2), (10, 2)]
        self.pilot['feature']['geometry'] = {'type': 'MultiPolygon', 'coordinates': [[ring(outer), ring(hole)], [ring(second)]]}
        self.pilot['walls'] = []
        _, doc, binary, manifest = self.export()
        self.assertEqual(manifest['walls_total'], 14)
        self.assertEqual(manifest['surfaces_total'], 16)
        roofs = [p for p in doc['meshes'][0]['primitives'] if p['extras'].get('kind') == 'roof']
        self.assertEqual(len(roofs), 2)
        allowed = Polygon(outer, [hole]).union(Polygon(second))
        area = 0
        edges = Counter()
        for p in doc['meshes'][0]['primitives']:
            vertices = accessor(doc, binary, p['attributes']['POSITION'])
            normals = accessor(doc, binary, p['attributes']['NORMAL'])
            indices = accessor(doc, binary, p['indices']).flatten().reshape(-1, 3)
            for indices3 in indices:
                tri = vertices[indices3]
                normal = np.cross(tri[1] - tri[0], tri[2] - tri[0])
                self.assertGreater(float(np.dot(normal, normals[indices3[0]])), 1e-7)
                if p['extras'].get('kind') == 'roof':
                    polygon = Polygon([(float(v[0]), -float(v[2])) for v in tri])
                    self.assertTrue(allowed.buffer(1e-5).covers(polygon))
                    area += polygon.area
                keys = [tuple(round(float(c), 5) for c in point) for point in tri]
                for a, b in zip(keys, keys[1:] + keys[:1]):
                    edges[tuple(sorted([a, b]))] += 1
        self.assertAlmostEqual(area, 23, places=4)
        self.assertEqual(set(edges.values()), {2}, '每個幾何邊需由兩個三角形共用，含內洞與底面')

    def test_cropped_split_texture_preserves_white_uncovered_wall_without_overlap(self):
        self.texture()
        wall = self.pilot['walls'][0]
        wall['extent'] = {'s_l': 1, 's_r': 3, 'h_bot': 1, 'h_top': 4}
        _, doc, binary, manifest = self.export()
        area = 0
        textured_area = 0
        edges = Counter()
        for p in doc['meshes'][0]['primitives']:
            positions = accessor(doc, binary, p['attributes']['POSITION'])
            triangles = accessor(doc, binary, p['indices']).flatten().reshape(-1, 3)
            for ids in triangles:
                tri = positions[ids]
                keys = [tuple(round(float(c), 5) for c in point) for point in tri]
                for a, b in zip(keys, keys[1:] + keys[:1]):
                    edges[tuple(sorted([a, b]))] += 1
                if 'wall_key' in p['extras'] and np.allclose(tri[:, 2], 0, atol=1e-5):
                    triangle_area = float(np.linalg.norm(np.cross(tri[1] - tri[0], tri[2] - tri[0])) / 2)
                    area += triangle_area
                    if p['extras']['textured']:
                        textured_area += triangle_area
        self.assertAlmostEqual(area, 20, places=4)
        self.assertAlmostEqual(textured_area, 6, places=4)
        self.assertEqual(set(edges.values()), {2})
        self.assertEqual(manifest['texture_status'], 'partial')

    def test_low_quality_missing_and_empty_alpha_never_count_as_textured(self):
        self.texture()
        self.pilot['walls'][0]['tier'] = 'C'
        _, doc, _, manifest = self.export()
        self.assertEqual(manifest['walls_textured'], 0)
        self.assertEqual(manifest['texture_status'], 'missing')
        self.assertEqual(doc.get('images', []), [])
        self.texture([(0, 0, 0, 0)] * 4)
        self.pilot['walls'][0]['tier'] = 'A'
        _, _, _, manifest = self.export()
        self.assertEqual(manifest['surfaces_textured'], 0)
        self.assertEqual(manifest['walls_missing'], manifest['walls_total'])

    def test_valid_coverage_uses_cropped_tex_pixels_and_rejects_fully_unknown_crop(self):
        self.texture([(0, 0, 0, 0), (255, 0, 0, 255), (0, 0, 0, 0), (0, 0, 255, 255)])
        wall = self.pilot['walls'][0]
        wall['a_lonlat'], wall['b_lonlat'] = lonlat(-4, 0), lonlat(4, 0)
        wall['length_m'] = 8
        wall['extent']['s_r'] = 8
        _, _, _, manifest = self.export()
        surface = next(s for s in manifest['surfaces'] if s['key'] == 'source:p0:r0:e0')
        self.assertAlmostEqual(surface['coverage'], 1)
        self.assertAlmostEqual(manifest['textures'][0]['valid_pixel_share'], .5)
        wall['a_lonlat'], wall['b_lonlat'] = lonlat(0, 0), lonlat(8, 0)
        _, doc, _, manifest = self.export()
        self.assertEqual(manifest['walls_textured'], 0)
        self.assertFalse(any(p['extras']['textured'] for p in doc['meshes'][0]['primitives']))

    def test_merged_reversed_source_edges_keep_one_logical_surface_per_original_edge(self):
        self.texture()
        # 官方輪廓可順時針；精確 source_edges 對照允許上游 merged segment 方向相反。
        self.pilot['feature']['geometry']['coordinates'] = [ring([(0, 0), (0, 3), (4, 3), (4, 0), (2, 0)])]
        wall = self.pilot['walls'][0]
        wall['source_edges'] = [
            {'polygon': 0, 'ring': 0, 'edge': 3, 's0': 2, 's1': 4, 'reversed': True},
            {'polygon': 0, 'ring': 0, 'edge': 4, 's0': 0, 's1': 2, 'reversed': True},
        ]
        _, doc, binary, manifest = self.export()
        self.assertEqual(manifest['walls_total'], 5)
        self.assertEqual(manifest['walls_textured'], 2)
        for p in doc['meshes'][0]['primitives']:
            if not p['extras']['textured']:
                continue
            pos = accessor(doc, binary, p['attributes']['POSITION'])
            uv = accessor(doc, binary, p['attributes']['TEXCOORD_0'])
            normal = accessor(doc, binary, p['attributes']['NORMAL'])
            np.testing.assert_allclose(uv[:, 0], pos[:, 0] / 4, atol=1e-6)
            np.testing.assert_allclose(normal, [[0, 0, 1]] * len(normal), atol=1e-6)
            self.assertEqual(p['extras']['upstream_wall_keys'], ['south'])

    def test_manifest_has_attribution_but_no_token_signed_url_or_local_texture_path(self):
        self.texture()
        self.pilot['token'] = 'MLY|SECRET'
        self.pilot['attribution'] = [{'image_id': '123', 'author': 'Photographer',
            'source_url': 'https://www.mapillary.com/app/?pKey=123&access_token=MLY%7CSECRET&signature=secret',
            'license': 'CC-BY-SA-4.0', 'changes': 'rectified; cropped', 'token': 'MLY|SECRET'}]
        self.pilot['walls'][0]['views'] = [{'download_url': 'https://example.com/?access_token=MLY|SECRET'}]
        _, doc, _, manifest = self.export()
        serialized = json.dumps(manifest)
        self.assertNotIn('SECRET', serialized)
        self.assertNotIn('signature', serialized)
        self.assertNotIn(str(self.folder), serialized)
        self.assertEqual(manifest['attribution'][0]['image_id'], '123')
        self.assertEqual(manifest['attribution'][0]['source_url'], 'https://www.mapillary.com/app/?pKey=123')
        self.assertNotIn('SECRET', json.dumps(doc))

    def test_manifest_preserves_engine_warnings_for_textured_and_missing_walls(self):
        self.texture()
        self.pilot['walls'][0].update({'confidence': .78, 'flags': ['SINGLE_VIEW', 'UNVERIFIED'],
            'upstream_height_m': 7.5, 'hole_fraction': .125, 'fuse_mode': 'single',
            'texture_source': 'single_view_resample', 'engine_observed_share': 1,
            'plane': {'source': 'osm-registered', 'rms_m': .3, 'token': 'MLY|SECRET'}})
        self.pilot['walls'].append({'key': 'unreachable', 'status': 'missing', 'tier': 'D',
            'confidence': 0, 'flags': ['NO_LINE_OF_SIGHT'], 'height_m': 5, 'observed_share': None})
        _, _, _, manifest = self.export()
        self.assertIn('wall_quality', manifest)
        quality = {wall['key']: wall for wall in manifest['wall_quality']}
        self.assertEqual(quality['south']['confidence'], .78)
        self.assertEqual(quality['south']['flags'], ['SINGLE_VIEW', 'UNVERIFIED'])
        self.assertEqual(quality['south']['upstream_height_m'], 7.5)
        self.assertEqual(quality['south'].get('hole_fraction'), .125)
        self.assertEqual(quality['south'].get('fuse_mode'), 'single')
        self.assertEqual(quality['south'].get('texture_source'), 'single_view_resample')
        self.assertEqual(quality['south'].get('engine_observed_share'), 1)
        self.assertEqual(quality['unreachable']['tier'], 'D')
        self.assertEqual(quality['unreachable']['flags'], ['NO_LINE_OF_SIGHT'])
        self.assertNotIn('SECRET', json.dumps(manifest))

    def test_manifest_preserves_global_registration_limits_without_private_metadata(self):
        self.texture()
        self.pilot['engine_quality'] = {'registration': {'panos': 5, 'local': 0, 'global': 5,
            'median_shift_m': 10.15, 'median_inliers_after': 0, 'acceptance_rate_local': 0,
            'local_rejection_reasons': {'only': 5, '25/40': 1}, 'token': 'MLY|SECRET'},
            'ground_sources': {'default': 5}, 'plane_sources': {'cloud': 0, 'osm-registered': 1},
            'warnings': ['GLOBAL_REGISTRATION', 'NO_CLOUD_PLANE', 'SINGLE_VIEW'], 'token': 'MLY|SECRET'}
        _, _, _, manifest = self.export()
        self.assertIn('engine_quality', manifest)
        self.assertEqual(manifest['engine_quality']['registration']['median_shift_m'], 10.15)
        self.assertEqual(manifest['engine_quality']['registration']['local_rejection_reasons'].get('25/40'), 1)
        self.assertEqual(manifest['engine_quality']['plane_sources'], {'cloud': 0, 'osm-registered': 1})
        self.assertIn('GLOBAL_REGISTRATION', manifest['engine_quality']['warnings'])
        self.assertNotIn('SECRET', json.dumps(manifest))

    def test_standalone_glb_carries_safe_attribution_and_quality_and_white_pbr_shading(self):
        self.texture()
        self.pilot['token'] = 'MLY|SECRET'
        self.pilot['attribution'] = [{'image_id': '123', 'author': 'Photographer',
            'source_url': 'https://www.mapillary.com/app/?pKey=123&access_token=MLY%7CSECRET',
            'license': 'CC-BY-SA-4.0', 'changes': 'rectified; cropped'}]
        self.pilot['walls'][0]['flags'] = ['SINGLE_VIEW', 'UNVERIFIED']
        _, doc, _, manifest = self.export()
        self.assertIn('extras', doc['asset'])
        metadata = doc['asset']['extras']['geotexture']
        self.assertEqual(metadata.get('verification_status'), 'trial_unverified')
        self.assertEqual(manifest.get('verification_status'), 'trial_unverified')
        for key in ('build_id', 'texture_status', 'attribution', 'wall_quality', 'engine_quality'):
            self.assertEqual(metadata[key], manifest[key])
        self.assertNotIn('SECRET', json.dumps(doc))
        self.assertNotIn(str(self.folder), json.dumps(doc))
        for p in doc['meshes'][0]['primitives']:
            material = doc['materials'][p['material']]
            if not p['extras']['textured']:
                self.assertNotIn('KHR_materials_unlit', material.get('extensions', {}))


if __name__ == '__main__':
    unittest.main()
