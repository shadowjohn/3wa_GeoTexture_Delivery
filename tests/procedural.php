<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use GeoTexture\App;
use GeoTexture\Recipes;
function ensure(bool $ok,string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function rejected(callable $fn): void { try { $fn(); } catch (InvalidArgumentException|RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
function encodedModel(array $document,string $binary): string {
    $json=json_encode($document,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    $json.=str_repeat(' ',(4-strlen($json)%4)%4);
    return 'glTF'.pack('VV',2,28+strlen($json)+strlen($binary)).pack('V',strlen($json)).'JSON'.$json.pack('V',strlen($binary))."BIN\0".$binary;
}
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
    $state=new PDO('sqlite:'.$tmp.'/state/app.sqlite');
    $frozen=$state->query("SELECT source_key,build_id,source_hash,recipe_key,feature_json FROM job_items WHERE recipe_key='procedural-tw-v1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $frozen['feature']=json_decode($frozen['feature_json'],true); unset($frozen['feature_json']);
    $inputPath=$tmp.'/legacy-input.json'; file_put_contents($inputPath,json_encode($frozen));
    $output=$tmp.'/state/artifacts/validation.glb';
    $python=dirname(__DIR__).'/.venv-procedural/bin/python';
    GeoTexture\ProceduralRunner::generate($inputPath,$output,is_executable($python)?$python:'python3');
    $manifestPath=$tmp.'/state/artifacts/validation.manifest.json';
    $valid=json_decode(file_get_contents($manifestPath),true);
    $input['path']='validation.glb'; $input['manifest_path']='validation.manifest.json';
    $bytes=file_get_contents($output); $jsonLength=unpack('V',substr($bytes,12,4))[1];
    $document=json_decode(substr($bytes,20,$jsonLength),true); $binary=substr($bytes,28+$jsonLength);
    ensure(count($document['meshes'][0]['primitives'])===3,'Batch same-material surfaces into three primitives');
    $ranges=$document['meshes'][0]['primitives'][0]['extras']['surface_ranges'];
    $badRanges=[[], ['bad'=>$ranges[0]]];
    foreach (['key'=>['',12,str_repeat('x',513)],'index_offset'=>[0.0,-3,3],'index_count'=>[0,1,999999999]] as $field=>$values) {
        foreach ($values as $value) { $bad=$ranges; $bad[0][$field]=$value; $badRanges[]=$bad; }
    }
    $bad=$ranges; $bad[1]['index_offset']-=3; $badRanges[]=$bad;
    $bad=$ranges; array_pop($bad); $badRanges[]=$bad;
    $bad=$ranges; $bad[0]['index_count']=3; $bad[1]['index_offset']=3; $bad[1]['index_count']=9; $badRanges[]=$bad;
    foreach ($badRanges as $bad) {
        $changed=$document; $changed['meshes'][0]['primitives'][0]['extras']['surface_ranges']=$bad;
        $changedBytes=encodedModel($changed,$binary); $changedManifest=$valid;
        $changedManifest['model_sha256']=hash('sha256',$changedBytes);
        file_put_contents($output,$changedBytes); file_put_contents($manifestPath,json_encode($changedManifest));
        rejected(fn()=>$tw->registerResult($input));
    }
    // Split shared accessors into legacy per-surface primitives without changing triangles.
    $legacyDocument=$document; $legacyDocument['meshes'][0]['primitives']=[];
    foreach ($document['meshes'][0]['primitives'] as $primitive) {
        foreach ($primitive['extras']['surface_ranges'] as $range) {
            $part=$primitive; unset($part['extras']['surface_ranges']);
            $part['extras'][$part['extras']['kind']==='wall' ? 'wall_key' : 'surface_key']=$range['key'];
            $indices=$document['accessors'][$part['indices']];
            $indices['byteOffset']=($indices['byteOffset'] ?? 0)+$range['index_offset']*4;
            $indices['count']=$range['index_count'];
            $part['indices']=count($legacyDocument['accessors']); $legacyDocument['accessors'][]=$indices;
            $legacyDocument['meshes'][0]['primitives'][]=$part;
        }
    }
    $legacyBytes=encodedModel($legacyDocument,$binary); $legacyManifest=$valid;
    $legacyManifest['model_sha256']=hash('sha256',$legacyBytes);
    file_put_contents($output,$legacyBytes); file_put_contents($manifestPath,json_encode($legacyManifest));
    ensure($tw->registerResult($input)['model']['surfaces_total']===$valid['surfaces_total'],'Legacy wall-key format remains accepted');
    file_put_contents($output,$bytes); file_put_contents($manifestPath,json_encode($valid));
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
