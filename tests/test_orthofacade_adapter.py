"""官方建物轉接與逐牆品質契約，不呼叫外部服務。"""
import hashlib
import importlib.util
import json
import sqlite3
import sys
from pathlib import Path

import pytest
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / 'vendor' / 'orthofacade'))


def adapter():
    from pipeline import orthofacade_adapter
    return orthofacade_adapter


def official(build_id='B-1', height=12.5, *, holes=False, multi=False):
    outer = [[121.42, 25.17], [121.4202, 25.17], [121.4202, 25.1702], [121.42, 25.1702], [121.42, 25.17]]
    rings = [outer]
    if holes:
        rings.append([[121.42005, 25.17005], [121.4201, 25.17005], [121.4201, 25.1701], [121.42005, 25.1701], [121.42005, 25.17005]])
    geometry = {'type': 'Polygon', 'coordinates': rings}
    if multi:
        geometry = {'type': 'MultiPolygon', 'coordinates': [rings, [[[x + 0.001, y] for x, y in outer]]]}
    return {'type': 'Feature', 'id': build_id, 'geometry': geometry,
            'properties': {'build_id': build_id, 'source_key': 'new-taipei-8418',
                           'source_hash': 'a' * 64, 'height': height, 'height_missing': height is None}}


def test_official_geometry_preserves_identifier_holes_height_and_hash():
    mod = adapter()
    import geo
    from common import Frame, Params
    source = official('中文原始ID', holes=True, multi=True)
    raw = json.dumps(source, sort_keys=True)
    osm, mapping = mod.official_cache([source], source['id'])
    parsed = geo.parse_overpass(osm, Frame(121.42, 25.17), Params())
    assert len(parsed) == 2
    assert len(parsed[0].holes) == 1
    assert all(part.height_osm == 12.5 for part in parsed)
    assert all(part.tags['_build_id'] == '中文原始ID' for part in parsed)
    assert set(mapping[source['id']]) == {part.key for part in parsed}
    assert json.dumps(source, sort_keys=True) == raw
    assert mod.validate_feature(source)['properties']['source_hash'] == 'a' * 64


def test_cache_identifiers_are_stable_when_neighbours_reorder():
    mod = adapter()
    first, second = official('A'), official('B')
    one, mapping_one = mod.official_cache([first, second], 'A')
    two, mapping_two = mod.official_cache([second, first], 'A')
    assert mapping_one == mapping_two
    a_one = [e for e in one['elements'] if e.get('tags', {}).get('_build_id') == 'A']
    a_two = [e for e in two['elements'] if e.get('tags', {}).get('_build_id') == 'A']
    assert a_one == a_two
    assert a_one[0]['tags']['_target'] is True


def test_rejects_unknown_height_missing_source_hash_and_invalid_geometry():
    mod = adapter()
    for feature in [official(height=None), official(height=0), official(height=float('inf'))]:
        with pytest.raises(ValueError):
            mod.validate_feature(feature)
    malformed = official()
    malformed['properties']['source_hash'] = ''
    with pytest.raises(ValueError):
        mod.validate_feature(malformed)
    malformed = official()
    malformed['geometry']['coordinates'][0][1] = [float('nan'), 25]
    with pytest.raises(ValueError):
        mod.validate_feature(malformed)


def test_frozen_php_hash_is_read_unchanged_and_database_is_not_written(tmp_path):
    mod = adapter()
    path = tmp_path / 'app.sqlite'
    con = sqlite3.connect(path)
    con.execute('CREATE TABLE job_items(job_id TEXT,build_id TEXT,source_key TEXT,source_hash TEXT,recipe_key TEXT,feature_json TEXT)')
    source = official()
    con.execute('INSERT INTO job_items VALUES(?,?,?,?,?,?)', ('job', 'B-1', 'new-taipei-8418', 'a' * 64, 'texture-v1', json.dumps(source)))
    con.commit()
    con.close()
    before = hashlib.sha256(path.read_bytes()).hexdigest()
    rows = mod.load_frozen_features(path, 'job')
    assert rows[0]['properties']['source_hash'] == 'a' * 64
    assert rows[0]['properties']['recipe_key'] == 'texture-v1'
    assert hashlib.sha256(path.read_bytes()).hexdigest() == before


def test_runs_have_separate_ascii_caches_and_reject_path_escape(tmp_path):
    mod = adapter()
    a = mod.pilot_directory(tmp_path, 'run-1')
    b = mod.pilot_directory(tmp_path, 'run-2')
    assert a != b
    assert a.parent == (tmp_path / 'db' / 'pilots').resolve()
    for bad in ['../escape', 'a/b', '中文', '']:
        with pytest.raises(ValueError):
            mod.pilot_directory(tmp_path, bad)


def test_existing_discovery_cannot_silently_reuse_another_bbox(tmp_path):
    mod = adapter()
    path = tmp_path / 'discovery.json'
    path.write_text(json.dumps({'bbox': [121.4, 25.1, 121.41, 25.11], 'images': []}), encoding='utf-8')
    with pytest.raises(ValueError):
        mod.load_discovery(path, (121.42, 25.12, 121.43, 25.13), None)


def test_metadata_and_errors_never_keep_tokens_or_signed_urls():
    mod = adapter()
    row = {'id': '1', 'computed_geometry': {'type': 'Point', 'coordinates': [121.4, 25.1]},
           'computed_rotation': [0, 0, 0], 'computed_altitude': 3,
           'sfm_cluster': {'id': '2', 'url': 'https://cdn.example/cloud?signature=secret'},
           'thumb_original_url': 'https://cdn.example/image?access_token=private-token'}
    safe = mod.safe_metadata(row)
    assert safe['sfm_cluster'] == {'id': '2'}
    assert 'url' not in json.dumps(safe)
    message = mod.redact_message('private-token https://cdn.example/image?access_token=private-token&signature=abc', ['private-token'])
    assert 'private-token' not in message and 'signature=abc' not in message


def test_mapillary_client_sends_own_token_only_in_graph_header():
    mod = adapter()
    calls = []
    class Response:
        status_code = 200
        def json(self):
            return {'data': []}
    class Session:
        def get(self, url, **kwargs):
            calls.append((url, kwargs))
            return Response()
    client = mod.MapillaryClient('private-token', session=Session())
    assert client.images((121.42, 25.17, 121.421, 25.171)) == []
    url, kwargs = calls[0]
    assert 'private-token' not in url and 'access_token' not in kwargs.get('params', {})
    assert kwargs['headers']['Authorization'] == 'OAuth private-token'
    with pytest.raises(ValueError):
        mod.MapillaryClient('')


def test_only_decodable_ab_images_with_observed_pixels_are_textured(tmp_path):
    mod = adapter()
    texture = tmp_path / 'out' / 'pilot' / 'texture' / 'tex'
    texture.mkdir(parents=True)
    Image.new('RGBA', (8, 8), (90, 120, 150, 255)).save(texture / 'good.png')
    records = [dict(key='wall-' + str(i), a_lonlat=[121.42, 25.17], b_lonlat=[121.4202, 25.17],
                    length_m=20, height_used_m=10, extent={'s_l': 1, 's_r': 19},
                    tier=tier, observed_share=observed, unknown_share=1-observed,
                    tex=filename, views=[]) for i, (tier, observed, filename) in enumerate([
                        ('A', .9, 'good.png'), ('B', .8, 'good.png'), ('C', .9, 'good.png'),
                        ('A', 0, 'good.png'), ('A', .9, 'absent.png')])]
    pilot = mod.make_manifest(official(), records, tmp_path, 'pilot', [], 'b' * 64)
    assert [wall['status'] for wall in pilot['walls']] == ['textured', 'textured', 'low_quality', 'low_quality', 'missing']
    assert pilot['source_hash'] == 'a' * 64
    assert pilot['walls'][0]['height_m'] == 12.5
    assert pilot['walls'][0]['extent']['h_top'] == 10
    assert pilot['walls'][0]['extent']['h_bot'] == 0
    assert pilot['walls'][0]['tex_path'] == str((texture / 'good.png').resolve())


def test_texture_vertical_extent_uses_render_rectangle_not_estimated_roof(tmp_path):
    mod = adapter()
    record = {'key': 'wall', 'a_lonlat': [121.42, 25.17], 'b_lonlat': [121.4202, 25.17],
              'height_used_m': 4.449789, 'rows': 4, 'extent': {'s_l': -.1, 's_r': 7.9},
              'render_rect': [-.1, 7.9, .25, 4.0], 'tier': 'D'}
    quality = {'registration': {'median_shift_m': 10.15}, 'warnings': ['UNVERIFIED']}
    pilot = mod.make_manifest(official(), [record], tmp_path, 'pilot', [], 'b'*64, engine_quality=quality)
    assert pilot['walls'][0]['extent']['h_bot'] == .25
    assert pilot['walls'][0]['extent']['h_top'] == 4.0
    assert pilot['walls'][0]['upstream_height_m'] == 4.449789
    assert pilot['engine_quality'] == quality


def test_source_edge_reverse_mapping_keeps_piece_local_intervals():
    mod = adapter()
    feature = official()
    first = mod.node_id('B-1', 0, 0, 0)
    second = mod.node_id('B-1', 0, 0, 1)
    rows = mod.source_edges(feature, {'edges': [{'node_a': second, 'node_b': first, 's0': 1.5, 's1': 3.0}]})
    assert rows[0]['edge'] == 0 and rows[0]['reversed'] is True
    assert (rows[0]['s0'], rows[0]['s1']) == (1.5, 3.0)


def test_single_view_uses_observed_alpha_before_inpainting(tmp_path):
    mod = adapter()
    texture = tmp_path / 'out/pilot/texture/tex'
    texture.mkdir(parents=True)
    Image.new('RGBA', (8, 8), (90, 120, 150, 255)).save(texture / 'fused.png')
    raw = Image.new('RGBA', (8, 8), (90, 120, 150, 0))
    raw.paste((90, 120, 150, 255), (0, 0, 4, 8))
    raw.save(texture / 'raw.png')
    row = {'key': 'wall', 'tex': 'fused.png', 'raw_tex': 'raw.png', 'tier': 'A', 'observed_share': 1,
           'height_used_m': 4, 'length_m': 8, 'a_lonlat': [121.42,25.17], 'b_lonlat': [121.4202,25.17]}
    pilot = mod.make_manifest(official(), [row], tmp_path, 'pilot', [], 'b'*64)
    assert pilot['walls'][0]['tex_path'].endswith('raw.png')
    assert pilot['walls'][0]['observed_share'] == .5
    assert pilot['walls'][0]['texture_source'] == 'single_view_resample'


@pytest.mark.parametrize('mode', ['fused', 'best_view'])
def test_inpainted_multiview_without_observed_mask_is_not_textured(tmp_path, mode):
    mod = adapter()
    texture = tmp_path / 'out/pilot/texture/tex'
    texture.mkdir(parents=True)
    Image.new('RGBA', (8, 8), (90, 120, 150, 255)).save(texture / 'filled.png')
    row = {'key': 'wall', 'tex': 'filled.png', 'tier': 'A', 'observed_share': 1,
           'fuse_mode': mode, 'hole_fraction': .1, 'height_used_m': 4, 'length_m': 8,
           'a_lonlat': [121.42,25.17], 'b_lonlat': [121.4202,25.17]}
    wall = mod.make_manifest(official(), [row], tmp_path, 'pilot', [], 'b'*64)['walls'][0]
    assert wall['status'] == 'low_quality' and wall['tex_path'] is None
    assert wall['observed_share'] == 0
    assert 'OBSERVED_MASK_REQUIRED' in wall['flags']


def test_cli_selects_nearby_images_for_each_candidate(tmp_path, monkeypatch):
    spec = importlib.util.spec_from_file_location('pilot_cli_test', ROOT / 'cli/orthofacade-pilot.py')
    cli = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(cli)
    first, second = official('A'), official('B')
    second['geometry']['coordinates'] = [[[x+.01,y] for x,y in ring] for ring in second['geometry']['coordinates']]
    metadata = [{'id': key, 'computed_geometry': {'type': 'Point', 'coordinates': [x,25.1701]},
                 'computed_rotation': [0,0,0], 'sfm_cluster': {'id':'cluster'}}
                for key,x in [('image-a',121.41994),('image-b',121.42994)]]
    class Client:
        def __init__(self, token):
            pass
        def images(self, bbox):
            return metadata
    seen = []
    def cache(client, rows, work):
        (work / 'cache').mkdir(exist_ok=True)
        return rows
    def process(feature, neighbours, images, work, token, **kwargs):
        seen.append((feature['id'], [row['id'] for row in images]))
        return {'build_id':feature['id'],'walls':[]}
    token = tmp_path / 'token.txt'
    token.write_text('private-test-token', encoding='utf-8')
    monkeypatch.setattr(cli, 'ROOT', tmp_path)
    monkeypatch.setattr(cli, 'MapillaryClient', Client)
    monkeypatch.setattr(cli, 'load_frozen_features', lambda *args: [first, second])
    monkeypatch.setattr(cli, 'neighbours_from_php', lambda *args: [first, second])
    monkeypatch.setattr(cli, 'cache_images', cache)
    monkeypatch.setattr(cli, 'run_official', process)
    monkeypatch.setattr(sys, 'argv', ['orthofacade-pilot.py','--run=test-candidates','--candidate-limit=2','--pano-count=3','--token-file='+str(token)])
    assert cli.main() == 3
    assert seen == [('A',['image-a']),('B',['image-b'])]
