<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use GeoTexture\App;
use GeoTexture\Recipes;
function ensure(bool $ok,string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function rejected(callable $fn): void { try { $fn(); } catch (InvalidArgumentException|RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
$tmp=sys_get_temp_dir().'/geotexture-procedural-'.bin2hex(random_bytes(6));
mkdir($tmp,0755);
try {
    ensure(class_exists(Recipes::class),'Recipe catalog is required');
    ensure(Recipes::validate('texture-v1')==='texture-v1','Legacy recipe remains valid');
    rejected(fn()=>Recipes::validate('../../bad'));
    $db=new PDO('sqlite:'.$tmp.'/source.sqlite');
    $db->exec('CREATE TABLE [8418](ogc_fid INTEGER PRIMARY KEY,build_id TEXT,buildname TEXT,build_h REAL,build_no INTEGER,GEOMETRY_WKT TEXT); CREATE VIRTUAL TABLE idx_8418_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax)');
    $db->exec("INSERT INTO [8418] VALUES(1,'B1','測試建物',12,4,'POLYGON ((121.4001 25.1701,121.4002 25.1701,121.4002 25.1702,121.4001 25.1702,121.4001 25.1701))'); INSERT INTO idx_8418_GEOMETRY VALUES(1,121.4001,121.4002,25.1701,25.1702)");
    $config=['source_db'=>$tmp.'/source.sqlite','state_dir'=>$tmp.'/state'];
    $legacy=new App($config);
    $tw=new App($config+['recipe_key'=>'procedural-tw-v1']);
    $modern=new App($config+['recipe_key'=>'procedural-modern-v1']);
    $polygon=['type'=>'Polygon','coordinates'=>[[[121.4,25.17],[121.401,25.17],[121.401,25.171],[121.4,25.171],[121.4,25.17]]]];
    $old=$legacy->createJob('實景',$polygon);
    $job=$tw->createJob('台灣',$polygon);
    ensure($job['recipe_key']==='procedural-tw-v1','Freeze recipe');
    $stats=$tw->runBatch(1,$job['id']);
    ensure($stats['processed']===1,'Process chosen job only');
    $job=$tw->job($job['id']);
    ensure($job['completed']===1 && $job['status']==='completed','Generate actual complete model');
    ensure($legacy->job($old['id'])['prepared']===0,'Do not process unrelated photo task');
    $model=$job['items'][0]['model'];
    ensure($model['appearance_kind']==='procedural' && $model['verification_status']==='synthetic','Label illustrative result');
    ensure($model['style_key']==='procedural-tw-v1','Preserve style');
    ensure(is_file($tw->modelFile($model['asset_id'])['path']),'Registered file exists');
    ensure($legacy->selection($polygon)['features'][0]['properties']['model']===null,'No leakage into photo view');
    ensure($modern->selection($polygon)['features'][0]['properties']['model']===null,'No leakage between styles');
    $other=$modern->createJob('現代',$polygon); $modern->runBatch(1,$other['id']);
    $other=$modern->job($other['id']);
    ensure($other['completed']===1 && $other['items'][0]['model']['asset_id']!==$model['asset_id'],'Keep both variants');
    ensure($tw->selection($polygon)['features'][0]['properties']['model']['asset_id']===$model['asset_id'],'Original style still available');
    $reuse=$tw->createJob('重用',$polygon);
    ensure($reuse['skipped']===1 && $reuse['completed']===1,'Reuse same recipe');
    $forced=$tw->createJob('強制',$polygon,true);
    ensure($forced['completed']===0,'Force creates pending work');
    $tw->cancelJob($forced['id']); $tw->runBatch(1,$forced['id']);
    ensure($tw->job($forced['id'])['status']==='cancelled','Cannot revive canceled job');
    rejected(fn()=>$tw->runBatch(1,str_repeat('f',24)));
    $zipPath=$tw->export($polygon); $zip=new ZipArchive(); $zip->open($zipPath);
    $manifest=json_decode($zip->getFromName('manifest.json'),true);
    ensure($manifest['recipe_key']==='procedural-tw-v1' && $manifest['completed']===1,'Export selected variant');
    ensure($manifest['items'][0]['model']['appearance_kind']==='procedural','Export synthetic label');
    $zip->close();
    // Procedural registration cannot bypass quality checks with an unlabelled GLB.
    $item=$job['items'][0];
    $input=array_intersect_key($item,array_flip(['source_key','build_id','source_hash','recipe_key']));
    $input['path']='models/'.$model['asset_id'].'.glb';
    rejected(fn()=>$tw->registerResult($input));
    $inputPath=null;
    foreach (glob($tmp.'/state/inputs/*.json') as $candidate) {
        if (json_decode(file_get_contents($candidate),true)['recipe_key']==='procedural-tw-v1') { $inputPath=$candidate; break; }
    }
    $output=$tmp.'/state/artifacts/validation.glb';
    $python=dirname(__DIR__).'/.venv-procedural/bin/python';
    GeoTexture\ProceduralRunner::generate($inputPath,$output,is_executable($python)?$python:'python3');
    $manifestPath=$tmp.'/state/artifacts/validation.manifest.json';
    $valid=json_decode(file_get_contents($manifestPath),true);
    $input['path']='validation.glb'; $input['manifest_path']='validation.manifest.json';
    foreach (['style_key'=>'texture-v1','verification_status'=>'verified','longitude'=>0,'surfaces_total'=>0,'model_sha256'=>str_repeat('0',64)] as $key=>$value) {
        $invalid=$valid; $invalid[$key]=$value;
        file_put_contents($manifestPath,json_encode($invalid));
        rejected(fn()=>$tw->registerResult($input));
    }
    $invalid=$valid; $invalid['textures'][0]['embedded_sha256']=str_repeat('0',64);
    file_put_contents($manifestPath,json_encode($invalid));
    rejected(fn()=>$tw->registerResult($input));
    file_put_contents($manifestPath,json_encode($valid));
    $validRegistration=$tw->registerResult($input);
    ensure($validRegistration['model']['verification_status']==='synthetic','Valid metadata accepted after tampering tests');
    foreach (glob($tmp.'/state/artifacts/models/*.glb') as $file) { unlink($file); }
    $lost=$tw->job($job['id']);
    ensure($lost['completed']===0 && $lost['items'][0]['status']==='queued','Missing procedural asset must queue regeneration');
    ensure($tw->runBatch(1,$job['id'])['completed']===1,'Lost procedural asset can be regenerated');
    echo "PASS procedural: variants / worker / reuse / cancel / export / registration\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname()); }
    rmdir($tmp);
}
