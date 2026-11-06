"""The persistent CLI isolates items and keeps legacy model/manifest bytes."""
import copy
import json
import os
import select
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from pipeline import procedural


class ProceduralBatchTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.request = {
            'source_key': 'test-source', 'build_id': 'fixture', 'source_hash': 'a' * 64,
            'recipe_key': 'procedural-tw-v1',
            'feature': {'type': 'Feature', 'properties': {'height': 14.4},
                        'geometry': {'type': 'Polygon', 'coordinates': [
                            [[121.418, 25.175], [121.4182, 25.175], [121.4182, 25.17514],
                             [121.418, 25.17514], [121.418, 25.175]]]}}}

    def start(self):
        process = subprocess.Popen([sys.executable, '-u', '-m', 'pipeline.procedural', '--batch'],
                                   cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, bufsize=0)

        def cleanup():
            if process.poll() is None:
                process.kill()
            process.wait(timeout=5)
            for stream in (process.stdin, process.stdout, process.stderr):
                stream.close()

        self.addCleanup(cleanup)
        return process

    def line(self, request=None, output=None):
        return (json.dumps({'request': self.request if request is None else request,
                            'output_path': str(output or self.folder / 'batch.glb')}) + '\n').encode()

    def send(self, process, line):
        try:
            remaining = memoryview(line)
            while remaining:
                remaining = remaining[process.stdin.write(remaining):]
            process.stdin.flush()
        except BrokenPipeError:
            self.fail('batch process closed stdin: ' + process.stderr.read().decode())

    def response(self, process):
        raw, deadline = b'', time.monotonic() + 10
        while not raw.endswith(b'\n'):
            ready, _, _ = select.select([process.stdout], [], [], max(0, deadline - time.monotonic()))
            self.assertTrue(ready, 'batch response must arrive before stdin closes')
            chunk = os.read(process.stdout.fileno(), 4096)
            if not chunk:
                self.fail('batch process closed stdout: ' + process.stderr.read().decode())
            raw += chunk
            self.assertLessEqual(len(raw), 8192, 'batch response must stay bounded')
        return json.loads(raw)

    def finish(self, process, code=0):
        process.stdin.close()
        self.assertEqual(process.wait(timeout=5), code, process.stderr.read().decode())
        self.assertEqual(process.stdout.read(), b'')

    def assert_error(self, response):
        self.assertEqual(set(response), {'ok', 'error'})
        self.assertIs(response['ok'], False)
        self.assertIsInstance(response['error'], str)
        self.assertTrue(response['error'])
        self.assertLessEqual(len(response['error']), 512)

    def assert_legacy_bytes(self, output, request):
        source, legacy = self.folder / 'input.json', self.folder / 'legacy.glb'
        source.write_text(json.dumps(request), encoding='utf-8-sig')
        procedural.generate(source, legacy)
        for suffix in ('.glb', '.manifest.json'):
            self.assertEqual(output.with_suffix(suffix).read_bytes(),
                             legacy.with_suffix(suffix).read_bytes())

    def test_generate_request_preserves_legacy_bytes_and_return_value_for_every_style(self):
        generate_request = getattr(procedural, 'generate_request', None)
        self.assertTrue(callable(generate_request), 'in-memory generation entrypoint is missing')
        for recipe in procedural.RECIPES:
            with self.subTest(recipe=recipe):
                self.request['recipe_key'] = recipe
                source, output = self.folder / 'input.json', self.folder / 'model.glb'
                source.write_text(json.dumps(self.request), encoding='utf-8-sig')
                expected = procedural.generate(source, output)
                model = output.read_bytes()
                manifest = output.with_suffix('.manifest.json').read_bytes()
                self.assertEqual(generate_request(self.request, output), expected)
                self.assertEqual(output.read_bytes(), model)
                self.assertEqual(output.with_suffix('.manifest.json').read_bytes(), manifest)

    def test_stream_replies_before_eof_and_survives_an_invalid_height(self):
        process = self.start()
        first, second = self.folder / 'first.glb', self.folder / 'nested' / 'second.glb'
        self.send(process, self.line(output=first))
        self.assertEqual(self.response(process), {'ok': True})
        self.assert_legacy_bytes(first, self.request)
        self.assertIsNone(process.poll())

        bad = copy.deepcopy(self.request)
        bad['feature']['properties']['height'] = 0
        rejected = self.folder / 'bad.glb'
        self.send(process, self.line(bad, rejected))
        self.assert_error(self.response(process))
        self.assertFalse(rejected.exists())

        other = copy.deepcopy(self.request)
        other.update(build_id='second', recipe_key='procedural-modern-v1')
        self.send(process, self.line(other, second))
        self.assertEqual(self.response(process), {'ok': True})
        self.assert_legacy_bytes(second, other)
        self.finish(process)

    def test_malformed_json_request_and_geometry_errors_allow_the_next_item(self):
        process = self.start()
        geometry = copy.deepcopy(self.request)
        geometry['feature']['geometry'] = {'type': 'Polygon', 'coordinates': []}
        invalid = [b'{bad json}\n', b'\xff\n', b'\n', b'[]\n', b'{}\n',
                   json.dumps({'request': None, 'output_path': str(self.folder / 'bad.glb')}).encode() + b'\n',
                   self.line({'recipe_key': 'unknown'}), self.line(geometry),
                   b'[' * 1100 + b']' * 1100 + b'\n']
        for line in invalid:
            with self.subTest(line=line[:60]):
                self.send(process, line)
                self.assert_error(self.response(process))
        self.send(process, self.line())
        self.assertEqual(self.response(process), {'ok': True})
        self.assert_legacy_bytes(self.folder / 'batch.glb', self.request)
        self.finish(process)

    def test_invalid_output_paths_report_bounded_errors_and_allow_the_next_item(self):
        process = self.start()
        directory = self.folder / 'directory.glb'
        directory.mkdir()
        manifest_output = self.folder / 'manifest-error.glb'
        manifest_output.with_suffix('.manifest.json').mkdir()
        invalid = [None, [], '', 'relative.glb', str(self.folder / 'wrong.json'),
                   str(directory), str(manifest_output), str(self.folder / ('x' * 2048 + '.glb')),
                   str(self.folder / ('\U0001f3e2' * 512 + '.glb'))]
        for output in invalid:
            with self.subTest(output=str(output)[:60]):
                line = (json.dumps({'request': self.request, 'output_path': output}) + '\n').encode()
                self.send(process, line)
                self.assert_error(self.response(process))
        self.send(process, self.line())
        self.assertEqual(self.response(process), {'ok': True})
        self.assert_legacy_bytes(self.folder / 'batch.glb', self.request)
        self.finish(process)

    def test_input_limit_includes_newline_and_accepts_exactly_the_limit(self):
        process = self.start()
        line = self.line().rstrip(b'\n')
        self.send(process, line + b' ' * (procedural.MAX_INPUT_BYTES - len(line) - 1) + b'\n')
        self.assertEqual(self.response(process), {'ok': True})
        self.assert_legacy_bytes(self.folder / 'batch.glb', self.request)
        self.finish(process)

        process = self.start()
        self.send(process, line + b' ' * (procedural.MAX_INPUT_BYTES - len(line)) + b'\n')
        self.assert_error(self.response(process))
        self.assertEqual(process.wait(timeout=5), 1)

    def test_oversized_line_exits_without_waiting_for_newline_or_eof(self):
        process = self.start()
        self.send(process, b' ' * (procedural.MAX_INPUT_BYTES + 1))
        self.assert_error(self.response(process))
        self.assertEqual(process.wait(timeout=5), 1)
        self.assertFalse((self.folder / 'batch.glb').exists())

    def test_eof_exits_normally_without_protocol_output(self):
        process = self.start()
        self.finish(process)

    def test_batch_flag_is_exclusive_with_legacy_positionals(self):
        for args in ([], ['input.json'], ['--batch', 'input.json'],
                     ['--batch', 'input.json', 'output.glb']):
            with self.subTest(args=args):
                result = subprocess.run([sys.executable, '-m', 'pipeline.procedural', *args],
                                        cwd=ROOT, capture_output=True, timeout=10)
                self.assertEqual(result.returncode, 2)
                self.assertEqual(result.stdout, b'')

    def test_legacy_cli_keeps_report_and_error_contract(self):
        source, output = self.folder / 'input.json', self.folder / 'single.glb'
        source.write_text(json.dumps(self.request), encoding='utf-8-sig')
        command = [sys.executable, '-m', 'pipeline.procedural', str(source), str(output)]
        result = subprocess.run(command, cwd=ROOT, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(json.loads(result.stdout), {
            'model_path': str(output), 'manifest_path': str(output.with_suffix('.manifest.json')),
            'texture_status': 'complete', 'appearance_kind': 'procedural',
            'walls_total': 4, 'walls_textured': 4, 'surfaces_total': 5, 'surfaces_textured': 5})
        self.assert_legacy_bytes(output, self.request)

        self.request['feature']['properties']['height'] = -1
        source.write_text(json.dumps(self.request), encoding='utf-8')
        result = subprocess.run(command, cwd=ROOT, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, b'')
        self.assertIn(b'Procedural generation failed:', result.stderr)


if __name__ == '__main__':
    unittest.main()
