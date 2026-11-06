"""Scene queue/download permissions use the same disposable authenticated site."""
import json
import os
import subprocess
import struct
from test_http_procedural import site, ROOT, RECIPE, POLYGON
import requests


def test_scene_permissions_snapshot_and_download(site, tmp_path):
    base, client, token = site
    api = base + 'api.php'
    body = {'recipe_key': RECIPE, 'polygon': POLYGON}
    for action in ('preview_scenes', 'create_scene_export', 'cancel_scene_export'):
        assert requests.post(api, params={'action': action}, json=body).status_code == 401
        assert client.post(api, params={'action': action}, json=body).status_code == 403
    assert client.get(api, params={'action':'meta','source_key':'../8'}).status_code == 422
    client.headers['X-CSRF-Token'] = token
    job = client.post(api, params={'action':'create_job'}, json={**body,'name':'scene HTTP'}).json()['data']
    result = client.post(api, params={'action':'run_batch'}, json={'id':job['id'],'batch':1})
    assert result.json()['data']['completed'] == 1
    response = client.post(api, params={'action':'create_scene_export'}, json=body)
    assert response.status_code == 201, response.text
    scene = response.json()['data']
    assert client.get(api, params={'action':'scene_file','id':scene['id']}).status_code == 404
    env = dict(os.environ,GEOTEXTURE_STATE_DIR=str(tmp_path/'state'),GEOTEXTURE_SOURCE_DB=str(tmp_path/'source.sqlite'))
    run = subprocess.run(['php','cli/worker.php','--batch=1'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
    assert run.returncode == 0, run.stderr
    status = client.get(api, params={'action':'scene_status','id':scene['id']}).json()['data']
    assert status['status'] == 'completed', status
    file = client.get(base + status['glb_url'])
    assert file.status_code == 200 and file.content[:4] == b'glTF'
    size = struct.unpack_from('<I',file.content,12)[0]
    doc = json.loads(file.content[20:20+size])
    assert doc['nodes'][0]['extras']['build_id'] == 'HTTP1'
    assert client.get(base + status['download_url']).content[:2] == b'PK'
    assert requests.get(base + status['glb_url'], headers={'If-None-Match':file.headers['ETag']}).status_code == 401
    assert client.get(base + status['glb_url'], headers={'If-None-Match':file.headers['ETag']}).status_code == 304
    assert client.get(api,params={'action':'scene_file','id':scene['id'],'format':'../input.json'}).status_code == 422
    assert client.get(api,params={'action':'scene_status','id':'../bad'}).status_code == 422
