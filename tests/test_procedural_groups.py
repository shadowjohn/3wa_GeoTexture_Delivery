"""Material batching preserves every indexed surface and its original appearance."""
import copy
import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from test_procedural import ROOT, accessor, parse_glb, procedural, ring


RECIPES = ('procedural-tw-v1', 'procedural-modern-v1', 'procedural-pastel-v1')
# Captured from ungrouped output at 5069668, before changing the generator.
# Each digest includes keyed, expanded triangle positions/normals/UVs, original
# per-surface flags/material, complete metadata, materials and embedded PNG hashes.
# Index values/buffer layout and the model hash are intentionally not included.
LEGACY_SNAPSHOTS = {
    'ordinary': (
        '4f398b13b48a832e9819114c8113a04ee9402938be1d4b21151291532ca69e84',
        '466efa977055fabbae4b27b8f1a01fa42e2075afabd2f4631a62668d05484c3b',
        '143227a0abc53583c81ca0a67057d40397919e51f1ca01ba0327d57b118ffcf9'),
    'concave': (
        '4377257e63633086ca7e3ee60fbf55d00fb7cab661798ac97a3a19bc7cb2a9ba',
        'a3c8476a22da45dfe3a13ef2c425be43692c840c3ae4bb25b92777965cd54653',
        '5672d1e0825df95ee4837f7c66861703ab70e8a1b2a52e8437a0e7bf8142fc94'),
    'courtyard': (
        'eef7f9ab7a43a39e33f4e8a9d02cbb53eaf6be73db3116461f98a069f52f06ac',
        'f61f6eb6c8dbdf7bdd134b7ad9c1ff8d0cc0ecb25e0ea9aac5d6e826c374704d',
        'cedcac2b60490b7ad5042e378b532afcec9487b630b1a2d5ed0fccc6a122dce5'),
    'multipart': (
        '83a25a45424faf4f9249a46e95366df7dcee0a9060b352d27cb3d0709f2d596c',
        '43d3de1f63deaf1834d5a63841a41c23e02db80f16c719e7432ba102f98cf51a',
        '7018f094828600015c648350a17a853c6ea54bf6d5178a285ddbc2550b5a5db0'),
    'reversed': (
        'a07954c4b4f2a07b3d1ed5191cd15d155925b2b36a33ca17bd1a01458b8c48fb',
        '7148d12731fc54662c1c54c13827bbdf12da6804ea3f62927ac042997c2e178b',
        'b9ae0f5efffebeae2739c5a256adf4cd9aae1c44c4b6ee9a5be0c6faddd37f10'),
}


def geometries():
    cases = {
        'ordinary': {'type': 'Polygon', 'coordinates': [
            ring([(0, 0), (20, 0), (20, 14), (0, 14)])]},
        'concave': {'type': 'Polygon', 'coordinates': [
            ring([(0, 0), (30, 0), (30, 10), (20, 10), (20, 30), (0, 30)])]},
        'courtyard': {'type': 'Polygon', 'coordinates': [
            ring([(0, 0), (30, 0), (30, 30), (0, 30)]),
            ring([(4, 4), (4, 12), (12, 12), (12, 4)])]},
    }
    cases['multipart'] = {'type': 'MultiPolygon', 'coordinates': [
        cases['concave']['coordinates'] + [cases['courtyard']['coordinates'][1]],
        [ring([(40, 0), (52, 0), (52, 8), (40, 8)])]]}
    cases['reversed'] = {'type': 'MultiPolygon', 'coordinates': [
        [r[::-1] for r in p] for p in cases['multipart']['coordinates']]}
    return cases


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


class ProceduralGroupsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.request = {
            'source_key': 'new-taipei-8418', 'build_id': 'fixture', 'source_hash': 'a' * 64,
            'recipe_key': 'procedural-tw-v1',
            'feature': {'type': 'Feature', 'properties': {'height': 14.4},
                        'geometry': geometries()['ordinary']}}

    def test_three_material_primitives_keep_complete_ranges_and_original_surfaces(self):
        for shape, geometry in geometries().items():
            for recipe, expected in zip(RECIPES, LEGACY_SNAPSHOTS[shape]):
                with self.subTest(shape=shape, recipe=recipe):
                    self.request['feature']['geometry'] = geometry
                    self.request['recipe_key'] = recipe
                    output = self.folder / 'grouped.glb'
                    result = procedural.generate_request(self.request, output)
                    doc, binary = parse_glb(output)
                    primitives = doc['meshes'][0]['primitives']
                    self.assertEqual(len(primitives), 3)
                    self.assertEqual([p['extras']['kind'] for p in primitives], ['wall', 'roof', 'base'])
                    self.assertEqual([p['material'] for p in primitives], [1, 2, 0])
                    surfaces = {}
                    for primitive in primitives:
                        extras = primitive['extras']
                        self.assertEqual(set(extras), {'kind', 'textured', 'required_texture', 'surface_ranges'})
                        self.assertEqual(primitive['mode'], 4)
                        indices = accessor(doc, binary, primitive['indices']).flatten()
                        self.assertTrue(extras['surface_ranges'])
                        offset = 0
                        for span in extras['surface_ranges']:
                            self.assertEqual(set(span), {'key', 'index_offset', 'index_count'})
                            self.assertIs(type(span['index_offset']), int)
                            self.assertIs(type(span['index_count']), int)
                            self.assertEqual(span['index_offset'], offset)
                            self.assertGreater(span['index_count'], 0)
                            self.assertEqual(span['index_count'] % 3, 0)
                            offset += span['index_count']
                            self.assertLessEqual(offset, len(indices))
                            self.assertNotIn(span['key'], surfaces)
                            selected = indices[span['index_offset']:offset]
                            surfaces[span['key']] = {
                                'material': primitive['material'], 'kind': extras['kind'],
                                'textured': extras['textured'], 'required_texture': extras['required_texture'],
                                'attributes': {name: accessor(doc, binary, index)[selected].tolist()
                                               for name, index in primitive['attributes'].items()}}
                        self.assertEqual(offset, len(indices))
                    textured_keys = {key for key, value in surfaces.items() if value['textured']}
                    self.assertEqual(textured_keys, {surface['key'] for surface in result['surfaces']})
                    metadata = doc['asset']['extras']['geotexture']
                    manifest = json.loads(output.with_suffix('.manifest.json').read_text())
                    self.assertEqual(manifest, {**metadata, 'model_sha256': hashlib.sha256(output.read_bytes()).hexdigest()})
                    texture_hashes = []
                    for image in doc['images']:
                        self.assertNotIn('uri', image)
                        view = doc['bufferViews'][image['bufferView']]
                        raw = binary[view['byteOffset']:view['byteOffset'] + view['byteLength']]
                        texture_hashes.append(hashlib.sha256(raw).hexdigest())
                    self.assertEqual(texture_hashes, [texture['embedded_sha256'] for texture in metadata['textures']])
                    snapshot = {
                        'surfaces': surfaces, 'metadata_sha256': digest(metadata),
                        'materials_sha256': digest({key: doc[key] for key in ('materials', 'textures', 'samplers')}),
                        'texture_hashes': texture_hashes}
                    self.assertEqual(digest(snapshot), expected, 'expanded surfaces and appearance must match ungrouped output')

    def test_grouped_multipart_batch_matches_single_file_bytes_for_all_styles(self):
        items = []
        for recipe in RECIPES:
            request = copy.deepcopy(self.request)
            request['recipe_key'] = recipe
            request['feature']['geometry'] = geometries()['multipart']
            output = self.folder / f'batch-{recipe}.glb'
            items.append({'request': request, 'output_path': str(output)})
        result = subprocess.run([sys.executable, '-m', 'pipeline.procedural', '--batch'],
                                input=''.join(json.dumps(item) + '\n' for item in items),
                                cwd=ROOT, capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([json.loads(line) for line in result.stdout.splitlines()], [{'ok': True}] * len(items))
        for item in items:
            with self.subTest(recipe=item['request']['recipe_key']):
                source, single = self.folder / 'input.json', self.folder / 'single.glb'
                source.write_text(json.dumps(item['request']), encoding='utf-8-sig')
                procedural.generate(source, single)
                output = Path(item['output_path'])
                self.assertEqual(len(parse_glb(output)[0]['meshes'][0]['primitives']), 3)
                for suffix in ('.glb', '.manifest.json'):
                    self.assertEqual(output.with_suffix(suffix).read_bytes(), single.with_suffix(suffix).read_bytes())


if __name__ == '__main__':
    unittest.main()
