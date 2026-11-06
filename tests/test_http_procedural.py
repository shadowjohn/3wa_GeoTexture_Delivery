"""Authenticated end-to-end API checks against disposable SQLite and a loopback PHP server."""
import hashlib
import io
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import time
import zipfile

import pytest
import requests

ROOT = Path(__file__).resolve().parents[1]
RECIPE = 'procedural-tw-v1'
POLYGON = {'type': 'Polygon', 'coordinates': [[[121.4,25.17],[121.401,25.17],[121.401,25.171],[121.4,25.171],[121.4,25.17]]]}

@pytest.fixture(params=[False, True], ids=['clean-source', 'repeated-source-vertices'])
def site(tmp_path, request):
    source=tmp_path/'source.sqlite'
    with sqlite3.connect(source) as db:
        db.executescript('CREATE TABLE [8418](ogc_fid INTEGER PRIMARY KEY,build_id TEXT,buildname TEXT,build_h REAL,build_no INTEGER,GEOMETRY_WKT TEXT); CREATE VIRTUAL TABLE idx_8418_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax);')
        wkt='POLYGON ((121.4001 25.1701,121.4002 25.1701,121.4002 25.1702,121.4001 25.1702,121.4001 25.1701))'
        if request.param:
            wkt=wkt.replace('121.4002 25.1702,', '121.4002 25.1702,121.4002 25.1702,')
        db.execute('INSERT INTO [8418] VALUES(1,?,?,?,?,?)',('HTTP1','示意測試',12,4,wkt))
        db.execute('INSERT INTO idx_8418_GEOMETRY VALUES(1,121.4001,121.4002,25.1701,25.1702)')
    sessions=tmp_path/'sessions'; sessions.mkdir()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    env=dict(os.environ,GEOTEXTURE_STATE_DIR=str(tmp_path/'state'),GEOTEXTURE_SOURCE_DB=str(source))
    log=open(tmp_path/'http.log','w+')
    process=subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],cwd=ROOT,env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}/'
    try:
        for _ in range(100):
            try:
                if requests.get(base+'login.php',timeout=2).status_code==200: break
            except requests.RequestException: pass
            time.sleep(.05)
        else: raise RuntimeError('PHP server did not start')
        token=hashlib.sha256(b'disposable-test-csrf').hexdigest()
        with sqlite3.connect(tmp_path/'state'/'app.sqlite') as db:
            stored=db.execute('SELECT password_hash FROM users WHERE id=1').fetchone()[0]
        version=hashlib.sha256(stored.encode()).hexdigest()
        (sessions/'sess_procedural-test').write_text(f'auth_user_id|i:1;auth_password_version|s:64:"{version}";auth_started_at|i:{int(time.time())};csrf_token|s:64:"{token}";')
        client=requests.Session(); client.cookies.set('geotexture_session','procedural-test')
        yield base,client,token
    finally:
        process.terminate(); process.wait(timeout=5); log.close()

def test_recipe_worker_and_export_permissions(site):
    base,client,csrf=site
    api=base+'api.php'
    assert requests.get(api,params={'action':'progress'}).status_code==401
    assert requests.post(api,params={'action':'run_batch'},json={'id':'0'*24}).status_code==401
    meta=client.get(api,params={'action':'meta'}).json()['data']
    assert set(meta['recipes'])=={'texture-v1',RECIPE,'procedural-modern-v1','procedural-pastel-v1'}
    assert meta['recipe_key']=='texture-v1'  # backward-compatible omitted parameter
    assert client.get(api,params={'action':'meta','recipe_key':'../../oops'}).status_code==422
    payload={'name':'HTTP 台灣風','polygon':POLYGON,'recipe_key':RECIPE}
    assert client.post(api,params={'action':'create_job'},json=payload).status_code==403
    client.headers['X-CSRF-Token']=csrf
    response=client.post(api,params={'action':'create_job'},json=payload)
    assert response.status_code==201,response.text
    job=response.json()['data']
    body={'id':job['id'],'batch':11}
    assert client.post(api,params={'action':'run_batch'},json=body).status_code==422
    body['batch']=1
    response=client.post(api,params={'action':'run_batch'},json=body,timeout=40)
    assert response.status_code==200,response.text
    assert response.json()['data']['completed']==1,response.text
    progress=client.get(api,params={'action':'progress'}).json()['data']
    assert progress['worker_running'] is False
    summary=next(row for row in progress['jobs'] if row['id']==job['id'])
    assert summary['completed']==1 and summary['queued']==0 and summary['failed']==0
    assert 'items' not in summary and 'polygon' not in summary
    item=client.get(api,params={'action':'job','id':job['id']}).json()['data']['items'][0]
    asset=item['model']['asset_id']
    assert item['model']['appearance_kind']=='procedural'
    assert requests.get(api,params={'action':'model','asset_id':asset}).status_code==401
    model=client.get(api,params={'action':'model','asset_id':asset})
    assert model.content[:4]==b'glTF'
    assert model.headers['Cache-Control']=='private, no-cache'
    cached=client.get(api,params={'action':'model','asset_id':asset},headers={'If-None-Match':model.headers['ETag']})
    assert cached.status_code==304 and cached.content==b''
    assert requests.get(api,params={'action':'model','asset_id':asset},headers={'If-None-Match':model.headers['ETag']}).status_code==401
    query={'action':'buildings','bbox':'121.4,25.17,121.401,25.171','recipe_key':RECIPE}
    full=client.get(api,params=query).json()['data']['features'][0]['properties']['model']
    preview=client.get(api,params={**query,'detail':'preview'}).json()['data']['features'][0]['properties']['model']
    assert 'surfaces' in full and 'textures' in full
    assert 'surfaces' not in preview and 'textures' not in preview
    for key in ['asset_id','url','longitude','latitude','appearance_kind','texture_status','surfaces_total']:
        assert preview[key]==full[key]
    assert client.post(api,params={'action':'export'},json={'polygon':POLYGON,'recipe_key':RECIPE}).status_code==200
    zipped=client.post(api,params={'action':'export'},json={'polygon':POLYGON,'recipe_key':RECIPE})
    with zipfile.ZipFile(io.BytesIO(zipped.content)) as archive:
        manifest=json.loads(archive.read('manifest.json'))
        assert manifest['completed']==1 and manifest['recipe_key']==RECIPE
        assert archive.read(manifest['items'][0]['model']['path'])==model.content
    other=client.post(api,params={'action':'selection'},json={'polygon':POLYGON,'recipe_key':'procedural-modern-v1'}).json()['data']
    assert other['features'][0]['properties']['model'] is None
