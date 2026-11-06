<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use GeoTexture\App;
function ok(bool $yes,string $why): void { if (!$yes) { throw new RuntimeException($why); } }
function fails(callable $fn): void { try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { return; } throw new RuntimeException('Expected rejection'); }
$tmp=sys_get_temp_dir().'/geotexture-scenes-'.bin2hex(random_bytes(5)); mkdir($tmp,0755);
try {
 $db=new PDO('sqlite:'.$tmp.'/source.sqlite');
 $db->exec('CREATE TABLE [8418](ogc_fid INTEGER PRIMARY KEY,build_id TEXT,buildname TEXT,build_h REAL,build_no INTEGER,GEOMETRY_WKT TEXT); CREATE VIRTUAL TABLE idx_8418_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax)');
 for ($i=1;$i<=3;$i++) { $x=121.4001+$i*.00015;$end=$x+.0001;
  $db->exec("INSERT INTO [8418] VALUES($i,'B$i','test',12,4,'POLYGON (($x 25.1701,$end 25.1701,$end 25.1702,$x 25.1702,$x 25.1701))'); INSERT INTO idx_8418_GEOMETRY VALUES($i,$x,$end,25.1701,25.1702)"); }
 $config=['source_db'=>$tmp.'/source.sqlite','state_dir'=>$tmp.'/state','recipe_key'=>'procedural-tw-v1'];
 $app=new App($config);$bbox=[121.4,25.17,121.401,25.171];
 $polygon=['type'=>'Polygon','coordinates'=>[[[121.4,25.17],[121.401,25.17],[121.401,25.171],[121.4,25.171],[121.4,25.17]]]];
 fails(fn()=>$app->createSceneExport($polygon));
 $job=$app->createJob('test',$polygon);$app->runBatch(2,$job['id']);
 $queued=$app->createSceneExport($polygon);ok($queued['status']==='queued' && $queued['total']===2,'Freeze only two ready inputs');
 ok($queued['recipe_key']===$config['recipe_key'] && array_column($queued['member_versions'],'asset_id')===$queued['members'],'Queued scenes expose frozen recipe and member identities');
 foreach($queued['member_versions'] as $member){$keys=array_keys($member);sort($keys);$expected=['source_key','build_id','source_hash','recipe_key','asset_id','longitude','latitude'];sort($expected);ok($keys===$expected,'Member metadata exposes only public version and placement fields');}
 ok($app->createSceneExport($polygon)['id']===$queued['id'],'Same inputs reuse job');
 $mask=umask(0077);try{$app->runBatch(1);}finally{umask($mask);}
 ok((fileperms($tmp.'/state/artifacts/scenes') & 0777)===0755,'Scene directory stays web-traversable under restrictive worker umask');
 $done=$app->sceneStatus($queued['id']);ok($done['status']==='completed','Background export completes: '.json_encode($done));
 ok($done['member_versions']===$queued['member_versions'],'Completion preserves frozen member versions');
 $state=new PDO('sqlite:'.$tmp.'/state/app.sqlite');$stored=$state->query("SELECT output_json FROM scene_jobs WHERE id='".$done['id']."'")->fetchColumn();
 $frozen=json_decode($state->query("SELECT request_json FROM scene_jobs WHERE id='".$done['id']."'")->fetchColumn(),true);
 ok(!array_key_exists('resident_bytes',$frozen['collection']['features'][0]['properties']['model']),'Cache estimates stay outside immutable export fingerprint and snapshot');
 $again=(new App($config))->sceneStatus($done['id']);
 ok(is_int($again['model']['resident_bytes']) && $again['model']['resident_bytes']===$done['stats']['triangles']*96+$done['stats']['texture_pixels']*4+65536,'Existing completed scene derives resident estimate from stored compiler statistics');
 ok($stored===$state->query("SELECT output_json FROM scene_jobs WHERE id='".$done['id']."'")->fetchColumn(),'Reading new metadata does not regenerate or mutate a completed scene');
 $zip=new ZipArchive();$zip->open($app->sceneFile($done['id'],'zip')['path']);
 $manifest=json_decode($zip->getFromName('manifest.json'),true);
 ok($manifest['missing']===1 && $manifest['completed']===2,'Frozen missing list survives later processing');
 ok($zip->locateName('scene/whole_block.glb')!==false,'Combined model in ZIP');
 $scene=json_decode($zip->getFromName('scene/manifest.json'),true);ok(count($scene['buildings'])===2,'Scene manifest has building identities');$zip->close();
 fails(fn()=>$app->previewScenes($bbox,[0,0]));
 $preview=$app->previewScenes($bbox);ok(count($preview['scenes'])>0,'Preview chunks queued');
 $narrow=[121.40024,25.17009,121.40036,25.17021];$visible=$app->buildings($narrow,2000,true)['features'];$edge=$app->previewScenes($narrow)['scenes'][0];
 ok(count($visible)===1 && count($edge['member_versions'])===2 && $edge['id']===$preview['scenes'][0]['id'],'Boundary preview includes authoritative versions outside the narrow viewport');
 $state->exec("UPDATE scene_jobs SET updated_at='2000-01-01T00:00:00+00:00' WHERE kind='preview'");
 $app->runBatch(1);$preview=$app->previewScenes($bbox);ok($preview['scenes'][0]['status']==='queued','Expired preview can be requested again');
 $app->runBatch(1);$preview=$app->previewScenes($bbox);ok($preview['scenes'][0]['status']==='completed','Ready preview');
 $new=$app->createSceneExport($polygon);ok($new['id']!==$done['id'],'New asset changes snapshot');
 $app->cancelSceneExport($new['id']);$app->runBatch(1);$cancelled=$app->sceneStatus($new['id']);ok($cancelled['status']==='cancelled' && $cancelled['member_versions']===$new['member_versions'],'Cancellation preserves frozen versions');
 $app->createSceneExport($polygon);$state=new PDO('sqlite:'.$tmp.'/state/app.sqlite');$state->exec("UPDATE scene_jobs SET status='running' WHERE id='".$new['id']."'");
 $app->runBatch(1);ok($app->sceneStatus($new['id'])['status']==='completed','Recover interrupted work under flock');
 $file=$app->sceneFile($new['id'],'glb');file_put_contents($file['path'],'corrupt');fails(fn()=>$app->sceneFile($new['id'],'glb'));
 $state->exec("UPDATE scene_jobs SET status='cancelled' WHERE id='".$new['id']."'");
 $state->exec("UPDATE scene_jobs SET status='cancelled',phase='expired' WHERE kind='preview'");
 $insert=$state->prepare("INSERT INTO scene_jobs SELECT ?,kind,request_json,'queued',0,'queued',NULL,NULL,created_at,updated_at FROM scene_jobs WHERE id=?");
 for($i=0;$i<100;$i++){$insert->execute([hash('sha256','capacity'.$i),$new['id']]);}
 fails(fn()=>$app->createSceneExport($polygon));fails(fn()=>$app->previewScenes($bbox));
 ok((int)$state->query("SELECT COUNT(*) FROM scene_jobs WHERE status IN ('queued','running')")->fetchColumn()===100,'Retry and expiry cannot bypass queue capacity');
 $original=$state->query('SELECT relative_path FROM assets ORDER BY rowid LIMIT 1')->fetchColumn();file_put_contents($tmp.'/state/artifacts/'.$original,'corrupt');fails(fn()=>$app->sceneFile($done['id'],'zip'));
 fails(fn()=>$app->sceneFile('../bad','glb'));fails(fn()=>$app->sceneFile($done['id'],'../bad'));
 $stub=$tmp.'/slow-scene';
 file_put_contents($stub,"#!/usr/bin/python3\nimport os,time\nopen(".json_encode($tmp.'/child.pid',JSON_UNESCAPED_SLASHES).",'w').write(str(os.getpid()))\nprint('{\"phase\":\"packing\",\"done\":1,\"total\":2}',flush=True)\ntime.sleep(60)\n");chmod($stub,0755);
 $started=microtime(true);
 fails(fn()=>GeoTexture\ProceduralRunner::scene($tmp.'/input.json',$tmp.'/unused.glb',$stub,static function($event){if($event!==null){throw new RuntimeException('cancel');}}));
 ok(microtime(true)-$started<3,'Cancel interrupts running child promptly');
 if(is_dir('/proc')){ok(!is_dir('/proc/'.trim(file_get_contents($tmp.'/child.pid'))),'Cancelled child is reaped');}
 echo "Scene queue, snapshot, atlas, ZIP, cancellation and integrity checks passed\n";
} finally {
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($iterator as $file) { $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); }rmdir($tmp);
}
