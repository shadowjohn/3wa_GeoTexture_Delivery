import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from urllib.parse import parse_qs, urlparse


SCRIPT = Path(__file__).resolve().parents[1] / 'cli' / 'mapillary-coverage.py'
probe = None
if SCRIPT.is_file():
    spec = importlib.util.spec_from_file_location('mapillary_coverage', SCRIPT)
    probe = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(probe)


class CoverageTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(probe, 'Mapillary 覆蓋查詢工具尚未實作')

    def test_rejects_invalid_or_overlarge_bbox(self):
        for value in ['121,25,120,26', 'nan,25,121,26', '121,25,122,26', 'a,b,c,d', '121,25,121,25']:
            with self.subTest(value=value), self.assertRaises(ValueError):
                probe.parse_bbox(value)
        self.assertEqual(probe.parse_bbox('121.412,25.1695,121.424,25.1815'), (121.412, 25.1695, 121.424, 25.1815))

    def test_uses_only_own_environment_or_file_token(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'token.txt'
            with self.assertRaises(ValueError):
                probe.load_token(path, None)
            path.write_text('MLY|file-test\n', encoding='utf-8')
            self.assertEqual(probe.load_token(path, None), 'MLY|file-test')
            self.assertEqual(probe.load_token(path, 'MLY|env-test'), 'MLY|env-test')
            with self.assertRaises(ValueError):
                probe.load_token(path, 'bad\nheader')

    def test_queries_metadata_without_token_in_url_and_marks_sampling(self):
        requests = []
        class Response:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, maximum):
                return json.dumps({'data': [
                    {'id': '100', 'is_pano': True, 'computed_geometry': {'type': 'Point', 'coordinates': [121.418, 25.175]}, 'computed_rotation': [0, 0, 1], 'computed_altitude': 5, 'sfm_cluster': {'id': '9'}},
                    {'id': '101', 'is_pano': True},
                ], 'paging': {'next': 'https://graph.mapillary.com/not-followed'}}).encode()
        def opener(request, timeout):
            requests.append((request, timeout))
            return Response()
        result = probe.query_coverage((121.412, 25.1695, 121.424, 25.1815), 'MLY|unit-test', opener=opener)
        self.assertEqual(len(requests), 1)
        request, timeout = requests[0]
        self.assertNotIn('MLY', request.full_url)
        self.assertEqual(request.get_header('Authorization'), 'OAuth MLY|unit-test')
        query = parse_qs(urlparse(request.full_url).query)
        self.assertEqual(query['is_pano'], ['true'])
        self.assertNotIn('thumb_original_url', query['fields'][0])
        self.assertEqual(timeout, 20)
        self.assertEqual(result['images_returned'], 2)
        self.assertEqual(result['usable_pose'], 1)
        self.assertEqual(result['with_sfm_cluster'], 1)
        self.assertTrue(result['has_more'])
        self.assertTrue(result['sample_only'])


if __name__ == '__main__':
    unittest.main()
