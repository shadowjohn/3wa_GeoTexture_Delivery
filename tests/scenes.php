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
 ok($app->createSceneExport($polygon)['id']===$queued['id'],'Same inputs reuse job');
 $app->runBatch(1);$done=$app->sceneStatus($queued['id']);ok($done['status']==='completed','Background export completes: '.json_encode($done));
 $zip=new ZipArchive();$zip->open($app->sceneFile($done['id'],'zip')['path']);
 $manifest=json_decode($zip->getFromName('manifest.json'),true);
 ok($manifest['missing']===1 && $manifest['completed']===2,'Frozen missing list survives later processing');
 ok($zip->locateName('scene/whole_block.glb')!==false,'Combined model in ZIP');
 $scene=json_decode($zip->getFromName('scene/manifest.json'),true);ok(count($scene['buildings'])===2,'Scene manifest has building identities');$zip->close();
 $preview=$app->previewScenes($bbox);ok(count($preview['scenes'])>0,'Preview chunks queued');
 $app->runBatch(1);$preview=$app->previewScenes($bbox);ok($preview['scenes'][0]['status']==='completed','Ready preview');
 $new=$app->createSceneExport($polygon);ok($new['id']!==$done['id'],'New asset changes snapshot');
 $app->cancelSceneExport($new['id']);$app->runBatch(1);ok($app->sceneStatus($new['id'])['status']==='cancelled','Cancellation persists');
 $app->createSceneExport($polygon);$state=new PDO('sqlite:'.$tmp.'/state/app.sqlite');$state->exec("UPDATE scene_jobs SET status='running' WHERE id='".$new['id']."'");
 $app->runBatch(1);ok($app->sceneStatus($new['id'])['status']==='completed','Recover interrupted work under flock');
 $file=$app->sceneFile($new['id'],'glb');file_put_contents($file['path'],'corrupt');fails(fn()=>$app->sceneFile($new['id'],'glb'));
 fails(fn()=>$app->sceneFile('../bad','glb'));fails(fn()=>$app->sceneFile($done['id'],'../bad'));
 echo "Scene queue, snapshot, atlas, ZIP, cancellation and integrity checks passed\n";
} finally {
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($iterator as $file) { $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); }rmdir($tmp);
}
