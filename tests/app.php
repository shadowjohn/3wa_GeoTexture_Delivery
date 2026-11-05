<?php
declare(strict_types=1);

use GeoTexture\App;

if (is_file(__DIR__ . '/../src/bootstrap.php')) { require_once __DIR__ . '/../src/bootstrap.php'; }

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function fixture(string $path, int $count = 2): void {
    $db = new PDO('sqlite:' . $path);
    $db->exec('CREATE TABLE [8418](ogc_fid INTEGER PRIMARY KEY,build_id TEXT,buildname TEXT,build_h REAL,build_no INTEGER,GEOMETRY_WKT TEXT)');
    $db->exec('CREATE VIRTUAL TABLE idx_8418_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax)');
    $db->beginTransaction();
    $s = $db->prepare('INSERT INTO [8418] VALUES(?,?,?,?,?,?)');
    $r = $db->prepare('INSERT INTO idx_8418_GEOMETRY VALUES(?,?,?,?,?)');
    for ($i=1; $i <= $count; $i++) {
        $x = $i === 2 ? 121.402 : 121.4001;
        $y = $i === 2 ? 25.172 : 25.1701;
        $x2=$x+0.0001; $y2=$y+0.0001;
        $s->execute([$i, 'B'.$i, '建物'.$i, 12, $i, "GEOMETRYCOLLECTION (POLYGON (($x $y,$x2 $y,$x2 $y2,$x $y2,$x $y)))"]);
        $r->execute([$i,$x,$x2,$y,$y2]);
    }
    $db->commit();
}
function triangleDocument(): array {
    return ['asset'=>['version'=>'2.0'],'scene'=>0,'scenes'=>[['nodes'=>[0]]],'nodes'=>[['mesh'=>0]],'meshes'=>[['primitives'=>[['attributes'=>['POSITION'=>0]]]]],'buffers'=>[['byteLength'=>36]],'bufferViews'=>[['buffer'=>0,'byteOffset'=>0,'byteLength'=>36]],'accessors'=>[['bufferView'=>0,'componentType'=>5126,'count'=>3,'type'=>'VEC3','min'=>[0,0,0],'max'=>[1,1,0]]]];
}
function encodeGlb(array $document, ?string $bin): string {
    $json=json_encode($document,JSON_THROW_ON_ERROR);
    $json.=str_repeat(' ',(4-strlen($json)%4)%4);
    $chunks=pack('V',strlen($json)).'JSON'.$json;
    if ($bin!==null) { $bin.=str_repeat("\0",(4-strlen($bin)%4)%4); $chunks.=pack('V',strlen($bin))."BIN\0".$bin; }
    return 'glTF'.pack('VV',2,12+strlen($chunks)).$chunks;
}
function triangleGlb(): string {
    return encodeGlb(triangleDocument(),pack('g*',0,0,0,1,0,0,0,1,0));
}
function texturedFixture(): array {
    $image=imagecreatetruecolor(2,2); imagefill($image,0,0,imagecolorallocate($image,120,80,50));
    ob_start(); imagepng($image); $png=(string)ob_get_clean(); imagedestroy($image);
    $d=triangleDocument(); $d['buffers'][0]['byteLength']=60+strlen($png);
    $d['bufferViews'][]=['buffer'=>0,'byteOffset'=>36,'byteLength'=>24];
    $d['bufferViews'][]=['buffer'=>0,'byteOffset'=>60,'byteLength'=>strlen($png)];
    $d['accessors'][]=['bufferView'=>1,'componentType'=>5126,'count'=>3,'type'=>'VEC2'];
    $d['materials']=[['pbrMetallicRoughness'=>['baseColorTexture'=>['index'=>0]]],['pbrMetallicRoughness'=>['baseColorFactor'=>[1,1,1,1]]]];
    $d['textures']=[['source'=>0]]; $d['images']=[['bufferView'=>2,'mimeType'=>'image/png']];
    $d['meshes'][0]['primitives'][0]=['attributes'=>['POSITION'=>0,'TEXCOORD_0'=>1],'material'=>0,'extras'=>['kind'=>'wall','wall_key'=>'wall-1','textured'=>true]];
    $d['meshes'][0]['primitives'][]=['attributes'=>['POSITION'=>0],'material'=>1,'extras'=>['kind'=>'wall','wall_key'=>'wall-1','textured'=>false]];
    $d['meshes'][0]['primitives'][]=['attributes'=>['POSITION'=>0],'material'=>1,'extras'=>['kind'=>'roof','surface_key'=>'roof-1','textured'=>false]];
    $d['meshes'][0]['primitives'][]=['attributes'=>['POSITION'=>0],'material'=>1,'extras'=>['kind'=>'base','required_texture'=>false,'textured'=>false]];
    return [$d,pack('g*',0,0,0,1,0,0,0,1,0,0,0,1,0,0,1).$png];
}

$tmp=sys_get_temp_dir().'/geotexture-app-'.bin2hex(random_bytes(6));
mkdir($tmp,0777,true);
ini_set('display_errors','1');
ini_set('error_log',$tmp.'/php-errors.log');
try {
    check(class_exists(App::class), 'App 任務服務尚未實作');
    fixture($tmp.'/source.sqlite');
    $config=['source_db'=>$tmp.'/source.sqlite','state_dir'=>$tmp.'/state','artifact_dir'=>$tmp.'/artifacts','recipe_key'=>'texture-v1'];
    $app=new App($config);
    $polygon=['type'=>'Polygon','coordinates'=>[[[121.4,25.17],[121.404,25.17],[121.404,25.174],[121.4,25.174],[121.4,25.17]]]];
    $job=$app->createJob('第一區',$polygon);
    check($job['total']===2 && $job['prepared']===0 && $job['completed']===0 && $job['status']==='queued','建立任務凍結兩棟且不可假完成');
    $id=$job['id'];
    $other=new App($config);
    check($other->job($id)['name']==='第一區','任務必須跨連線持久化');
    $stats=$app->runBatch(1);
    $job=$app->job($id);
    check($stats['processed']===1 && $job['prepared']===1 && $job['completed']===0 && $job['status']==='preparing','批次上限與準備進度分開');
    $app->cancelJob($id);
    $app->runBatch(100);
    check($app->job($id)['status']==='cancelled' && $app->job($id)['prepared']===1,'worker 不可復活已取消任務');
    $app->retryJob($id);
    $app->runBatch(100);
    $job=$app->job($id);
    check($job['prepared']===2 && $job['completed']===0 && $job['status']==='awaiting_converter','沒有轉換器時維持待轉換');
    check(count($job['items'])===2 && $job['items'][0]['source_hash']!=='','詳情提供凍結版本項目');
    check(is_numeric($job['items'][0]['height'] ?? null) && (float)$job['items'][0]['height']===12.0 && ($job['items'][0]['height_missing'] ?? null)===false,'詳情項目保留來源高度與缺值狀態');
    $lock=fopen($config['state_dir'].'/worker.lock','c'); flock($lock,LOCK_EX);
    check($app->runBatch(10)['locked']===true,'重疊worker應跳過');
    flock($lock,LOCK_UN); fclose($lock);
    mkdir($config['artifact_dir'].'/incoming',0777,true);
    file_put_contents($config['artifact_dir'].'/incoming/triangle.glb',triangleGlb());
    $item=$job['items'][0];
    $registration=['source_key'=>$item['source_key'],'build_id'=>$item['build_id'],'source_hash'=>$item['source_hash'],'recipe_key'=>$item['recipe_key'],'path'=>'incoming/triangle.glb'];
    $negativeModels = ['empty-mesh'=>encodeGlb(['asset'=>['version'=>'2.0'],'meshes'=>[new stdClass()]],null)];
    $mutations = [
        'unreachable-mesh'=>static function(array &$d): void { $d['scenes'][0]['nodes']=[]; },
        'bad-scene'=>static function(array &$d): void { $d['scene']=5; },
        'bad-node'=>static function(array &$d): void { $d['scenes'][0]['nodes']=[4]; },
        'cyclic-nodes'=>static function(array &$d): void { $d['nodes'][0]['children']=[0]; },
        'bad-mesh'=>static function(array &$d): void { $d['nodes'][0]['mesh']=7; },
        'missing-primitives'=>static function(array &$d): void { $d['meshes'][0]=[]; },
        'missing-position'=>static function(array &$d): void { $d['meshes'][0]['primitives'][0]['attributes']=[]; },
        'bad-position-index'=>static function(array &$d): void { $d['meshes'][0]['primitives'][0]['attributes']['POSITION']=7; },
        'bad-accessor-view'=>static function(array &$d): void { $d['accessors'][0]['bufferView']=7; },
        'bad-view-buffer'=>static function(array &$d): void { $d['bufferViews'][0]['buffer']=7; },
        'short-view'=>static function(array &$d): void { $d['bufferViews'][0]['byteLength']=35; },
        'view-past-buffer'=>static function(array &$d): void { $d['bufferViews'][0]['byteOffset']=4; },
        'accessor-past-view'=>static function(array &$d): void { $d['accessors'][0]['byteOffset']=4; },
        'stride-too-small'=>static function(array &$d): void { $d['bufferViews'][0]['byteStride']=8; },
        'count-past-view'=>static function(array &$d): void { $d['accessors'][0]['count']=4; },
        'wrong-position-type'=>static function(array &$d): void { $d['accessors'][0]['type']='VEC2'; },
        'missing-position-bounds'=>static function(array &$d): void { unset($d['accessors'][0]['min']); },
        'incomplete-triangle'=>static function(array &$d): void { $d['accessors'][0]['count']=2; },
        'short-data-uri'=>static function(array &$d): void { $d['buffers'][0]['uri']='data:application/octet-stream;base64,AA=='; },
    ];
    foreach ($mutations as $label=>$mutate) {
        $document=triangleDocument(); $mutate($document);
        $negativeModels[$label]=encodeGlb($document,pack('g*',0,0,0,1,0,0,0,1,0));
    }
    $negativeModels['nan-position']=encodeGlb(triangleDocument(),pack('g*',NAN,0,0,1,0,0,0,1,0));
    $indexed=triangleDocument();
    $indexed['buffers'][0]['byteLength']=42;
    $indexed['bufferViews'][]=['buffer'=>0,'byteOffset'=>36,'byteLength'=>6];
    $indexed['accessors'][]=['bufferView'=>1,'componentType'=>5123,'count'=>3,'type'=>'SCALAR'];
    $indexed['meshes'][0]['primitives'][0]['indices']=1;
    $negativeModels['out-of-range-indices']=encodeGlb($indexed,pack('g*',0,0,0,1,0,0,0,1,0).pack('v*',0,1,3));
    foreach ($negativeModels as $label=>$bytes) {
        file_put_contents($config['artifact_dir'].'/incoming/'.$label.'.glb',$bytes);
        rejects(fn()=>$app->registerResult(array_merge($registration,['path'=>'incoming/'.$label.'.glb'])),'不可登錄無法繪製的模型：'.$label);
    }
    check($app->job($id)['completed']===0,'拒絕無效網格不能改變完成數');
    $asset=$app->registerResult($registration);
    check($app->job($id)['completed']===1,'只有已登錄有效GLB可算完成');
    $selected=$app->selection($polygon);
    check($selected['features'][0]['properties']['model']['asset_id']===$asset['asset_id'],'選取結果應提供模型下載資訊');
    $overlap=$app->createJob('重疊區',$polygon);
    check($overlap['completed']===1 && $overlap['skipped']===1,'重疊任務重用相同版本成果');
    $force=$app->createJob('強制重做',$polygon,true);
    check($force['completed']===0 && $force['total']===2,'force應建立待處理項目且保留成果');
    check(is_file($app->modelFile($asset['asset_id'])['path']),'force不應刪除既有成果');
    $app->runBatch(100);
    check($app->job($force['id'])['status']==='awaiting_converter' && $app->job($force['id'])['completed']===0,'force前置完成後仍須新登錄成果，不能套用原成果');
    rejects(fn()=>$app->registerResult(array_merge($registration,['source_hash'=>str_repeat('0',64)])),'不符凍結版本不得登錄');
    rejects(fn()=>$app->registerResult(array_merge($registration,['path'=>'../source.sqlite'])),'不得跨artifact root取檔');
    file_put_contents($config['artifact_dir'].'/incoming/bad.glb','not a model');
    rejects(fn()=>$app->registerResult(array_merge($registration,['path'=>'incoming/bad.glb'])),'無效GLB不可登錄');
    file_put_contents($config['artifact_dir'].'/incoming/short-buffer.glb',str_replace('"byteLength":36','"byteLength":99',triangleGlb()));
    rejects(fn()=>$app->registerResult(array_merge($registration,['path'=>'incoming/short-buffer.glb'])),'GLB宣告的buffer超出BIN chunk時不可登錄');
    $zipPath=$app->export($polygon);
    $zip=new ZipArchive(); $zip->open($zipPath);
    $manifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
    check($zip->locateName('selection.geojson')!==false && $zip->locateName('buildings.geojson')!==false,'匯出包含範圍與建物');
    check($manifest['completed']===1 && $manifest['missing']===1 && $zip->locateName('models/'.$asset['asset_id'].'.glb')!==false,'匯出只附實際存在成果並列缺漏');
    $zip->close(); unlink($zipPath);
    $independent=['type'=>'Polygon','coordinates'=>[[[121.4,25.17],[121.401,25.17],[121.401,25.171],[121.4,25.171],[121.4,25.17]]]];
    $zipPath=$app->export($independent); $zip->open($zipPath);
    $small=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
    check($small['total']===1 && $small['completed']===1,'獨立匯出範圍不能套用原任務建物清單');
    $zip->close(); unlink($zipPath);
    $edit=new PDO('sqlite:'.$tmp.'/source.sqlite');
    $edit->exec('UPDATE [8418] SET build_h=99 WHERE build_id=\'B1\''); unset($edit);
    $changed=$app->createJob('來源改版',$polygon);
    check($changed['completed']===0 && $changed['items'][0]['source_hash']!==$item['source_hash'],'來源改版不可重用舊hash成果');
    check($app->job($id)['completed']===1,'既有任務仍保留原凍結版本');
    unlink($app->modelFile($asset['asset_id'])['path']);
    check($app->job($id)['completed']===0,'成果遺失應撤回完成計數');
    check($app->selection($polygon)['features'][0]['properties']['model']===null,'遺失成果不能回傳模型');
    $failureConfig=array_merge($config,['state_dir'=>$tmp.'/failure-state']);
    $failureApp=new App($failureConfig);
    $failure=$failureApp->createJob('故障重試',$polygon);
    file_put_contents($failureConfig['state_dir'].'/inputs','blocked');
    $failureApp->runBatch(100);
    $failure=$failureApp->job($failure['id']);
    check($failure['status']==='failed' && $failure['failed']===2 && $failure['error']!==null,'前置作業故障應可見');
    unlink($failureConfig['state_dir'].'/inputs');
    $failureApp->retryJob($failure['id']); $failureApp->runBatch(100);
    check($failureApp->job($failure['id'])['status']==='awaiting_converter','失敗項目重試可恢復且仍不假完成');
    $outside=['type'=>'Polygon','coordinates'=>[[[121.41,25.18],[121.411,25.18],[121.411,25.181],[121.41,25.181],[121.41,25.18]]]];
    rejects(fn()=>$app->createJob('空範圍',$outside),'零建物區域不能建立任務');
    rejects(fn()=>$app->createJob('  ',$polygon),'空名稱不能建立任務');
    $missing=new App(array_merge($config,['source_db'=>$tmp.'/missing.sqlite']));
    check($missing->meta()['source']['ready']===false && $missing->meta()['source']['error']!=='','缺來源仍可呈現meta錯誤');
    fixture($tmp.'/many.sqlite',10001);
    $large=new App(array_merge($config,['source_db'=>$tmp.'/many.sqlite','state_dir'=>$tmp.'/many-state']));
    rejects(fn()=>$large->createJob('過量範圍',$polygon),'超過上限不得靜默建立部分任務');
    rejects(fn()=>$large->export($polygon),'超過上限不得靜默匯出部分建物');
    check(count($large->jobs())===0,'拒絕過量任務不應留下部分清單');
    $validModels=['indexed'=>encodeGlb($indexed,pack('g*',0,0,0,1,0,0,0,1,0).pack('v*',0,1,2))];
    $dataUri=triangleDocument(); $dataUri['buffers'][0]['uri']='data:application/octet-stream;base64,'.base64_encode(pack('g*',0,0,0,1,0,0,0,1,0));
    $validModels['data-uri']=encodeGlb($dataUri,null);
    $interleaved=triangleDocument(); $interleaved['buffers'][0]['byteLength']=48;
    $interleaved['bufferViews'][0]['byteLength']=48; $interleaved['bufferViews'][0]['byteStride']=16;
    $validModels['interleaved']=encodeGlb($interleaved,pack('g*',0,0,0,99,1,0,0,99,0,1,0,99));
    $nested=triangleDocument(); $nested['nodes'][]=['children'=>[0]]; $nested['scenes'][0]['nodes']=[1];
    $validModels['nested-scene']=encodeGlb($nested,pack('g*',0,0,0,1,0,0,0,1,0));
    foreach ($validModels as $label=>$bytes) {
        file_put_contents($config['artifact_dir'].'/incoming/'.$label.'.glb',$bytes);
        $valid=$app->registerResult(array_merge($registration,['path'=>'incoming/'.$label.'.glb']));
        check(is_file($app->modelFile($valid['asset_id'])['path']),'有效核心網格應可登錄：'.$label);
    }
    fixture($tmp.'/pilot-source.sqlite');
    $pilotConfig=array_merge($config,['source_db'=>$tmp.'/pilot-source.sqlite','state_dir'=>$tmp.'/pilot-state','artifact_dir'=>$tmp.'/pilot-artifacts']);
    $pilotApp=new App($pilotConfig); $pilotJob=$pilotApp->createJob('真貼皮登錄測試',$polygon); $pilotApp->runBatch(100);
    $pilotItem=$pilotJob['items'][0]; mkdir($pilotConfig['artifact_dir'].'/incoming',0777,true);
    $pilotRegistration=['source_key'=>$pilotItem['source_key'],'build_id'=>$pilotItem['build_id'],'source_hash'=>$pilotItem['source_hash'],'recipe_key'=>$pilotItem['recipe_key'],'path'=>'incoming/model.glb','manifest_path'=>'incoming/model.manifest.json'];
    $quality=['version'=>1,'source_key'=>$pilotItem['source_key'],'build_id'=>$pilotItem['build_id'],'source_hash'=>$pilotItem['source_hash'],'recipe_key'=>$pilotItem['recipe_key'],'engine_commit'=>str_repeat('a',40),'imagery_hash'=>str_repeat('b',64),'surfaces_total'=>2,'surfaces_textured'=>1,'walls_total'=>1,'walls_textured'=>1,'texture_status'=>'partial','model_sha256'=>'','attribution'=>[['image_id'=>'123','author'=>null,'source_url'=>'https://www.mapillary.com/app/?pKey=123','license'=>'CC-BY-SA-4.0','changes'=>'單元測試紋理']],'longitude'=>121.40015,'latitude'=>25.17015,'altitude'=>0,'heading'=>0,'scale'=>1,'up_axis'=>'Y','forward_axis'=>'-Z'];
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',triangleGlb());
    $quality['model_sha256']=hash('sha256',triangleGlb());
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($quality,JSON_THROW_ON_ERROR));
    rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'白模不可搭配假manifest冒充真貼皮');
    [$texturedDocument,$texturedBinary]=texturedFixture(); $texturedBytes=encodeGlb($texturedDocument,$texturedBinary);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$texturedBytes); $quality['model_sha256']=hash('sha256',$texturedBytes);
    $quality['surfaces']=[['key'=>'wall-1','kind'=>'wall','textured'=>true,'area_m2'=>1,'geometric_coverage'=>0.5,'coverage'=>0.5,'upstream_wall_keys'=>[]],['key'=>'roof-1','kind'=>'roof','textured'=>false,'area_m2'=>0.5,'geometric_coverage'=>0,'coverage'=>0,'upstream_wall_keys'=>[]]];
    $quality['textures']=[['upstream_wall_key'=>'test-wall','source_sha256'=>hash('sha256',substr($texturedBinary,60)),'embedded_sha256'=>hash('sha256',substr($texturedBinary,60)),'width'=>2,'height'=>2,'tier'=>'A','valid_pixel_share'=>1,'observed_share'=>1]];
    $quality['verification_status']='trial_unverified';
    $quality['wall_quality']=[['key'=>'test-wall','status'=>'textured','tier'=>'A','flags'=>['SINGLE_VIEW','UNVERIFIED'],'confidence'=>0.8,'height_m'=>12,'upstream_height_m'=>13,'hole_fraction'=>0.015625,'fuse_mode'=>'single','texture_source'=>'raw-single-view-no-fill','engine_observed_share'=>1,'plane'=>['source'=>'osm-registered','pano'=>'123','rms_m'=>0.1,'path'=>'do-not-publish'],'token'=>'do-not-publish']];
    $quality['engine_quality']=['registration'=>['panos'=>1,'local'=>0,'global'=>1,'acceptance_rate_any'=>1,'local_rejection_reasons'=>['only'=>1],'token'=>'do-not-publish'],'ground_sources'=>['default'=>1],'plane_sources'=>['osm-registered'=>1],'warnings'=>['GLOBAL_REGISTRATION','SINGLE_VIEW'],'path'=>'do-not-publish'];
    foreach (['source_key'=>'wrong','build_id'=>'wrong','source_hash'=>str_repeat('0',64),'recipe_key'=>'wrong','model_sha256'=>str_repeat('0',64),'imagery_hash'=>'invalid','surfaces_total'=>3,'surfaces_textured'=>2,'walls_total'=>2,'walls_textured'=>0,'texture_status'=>'complete','up_axis'=>'Z'] as $field=>$invalidValue) {
        file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode(array_replace($quality,[$field=>$invalidValue]),JSON_THROW_ON_ERROR));
        rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'manifest不可偽造：'.$field);
    }
    foreach (['false-area'=>static function(array &$m): void { $m['surfaces'][0]['area_m2']=9; },'false-geometric-coverage'=>static function(array &$m): void { $m['surfaces'][0]['geometric_coverage']=1; },'false-coverage'=>static function(array &$m): void { $m['surfaces'][0]['coverage']=1; },'wrong-embedded-sha'=>static function(array &$m): void { $m['textures'][0]['embedded_sha256']=str_repeat('0',64); },'wrong-image-size'=>static function(array &$m): void { $m['textures'][0]['width']=999; },'low-tier'=>static function(array &$m): void { $m['textures'][0]['tier']='C'; },'no-observed-pixels'=>static function(array &$m): void { $m['textures'][0]['observed_share']=0; }] as $label=>$mutate) {
        $badQuality=$quality; $mutate($badQuality);
        file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($badQuality,JSON_THROW_ON_ERROR));
        rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'逐面與紋理品質不可偽造：'.$label);
    }
    foreach (['missing-uv'=>static function(array &$d): void { unset($d['meshes'][0]['primitives'][0]['attributes']['TEXCOORD_0']); },'wrong-uv-type'=>static function(array &$d): void { $d['accessors'][1]['type']='SCALAR'; },'bad-image-index'=>static function(array &$d): void { $d['textures'][0]['source']=9; },'bad-image-range'=>static function(array &$d): void { $d['bufferViews'][2]['byteLength']=999999; },'external-image'=>static function(array &$d): void { $d['images'][0]=['uri'=>'file:///private/image.png']; }] as $label=>$mutate) {
        $badDocument=$texturedDocument; $mutate($badDocument); $badBytes=encodeGlb($badDocument,$texturedBinary);
        file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$badBytes);
        file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode(array_replace($quality,['model_sha256'=>hash('sha256',$badBytes)]),JSON_THROW_ON_ERROR));
        rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'真貼皮材質鏈必須有效：'.$label);
    }
    $badBytes=encodeGlb($texturedDocument,substr($texturedBinary,0,60).str_repeat('X',strlen($texturedBinary)-60));
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$badBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode(array_replace($quality,['model_sha256'=>hash('sha256',$badBytes)]),JSON_THROW_ON_ERROR));
    rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'內嵌圖片必須真的能解碼');
    $zeroArea=$texturedDocument; $zeroBinary=$texturedBinary.str_repeat("\0",(4-strlen($texturedBinary)%4)%4);
    $zeroArea['bufferViews'][]=['buffer'=>0,'byteOffset'=>strlen($zeroBinary),'byteLength'=>36];
    $zeroBinary.=str_repeat("\0",36); $zeroArea['buffers'][0]['byteLength']=strlen($zeroBinary);
    $zeroArea['accessors'][]=['bufferView'=>3,'componentType'=>5126,'count'=>3,'type'=>'VEC3','min'=>[0,0,0],'max'=>[0,0,0]];
    $zeroArea['meshes'][0]['primitives'][0]['attributes']['POSITION']=2;
    $zeroBytes=encodeGlb($zeroArea,$zeroBinary); $zeroQuality=$quality;
    $zeroQuality['surfaces'][0]['area_m2']=0.5; $zeroQuality['surfaces'][0]['geometric_coverage']=0; $zeroQuality['surfaces'][0]['coverage']=0;
    $zeroQuality['model_sha256']=hash('sha256',$zeroBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$zeroBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($zeroQuality,JSON_THROW_ON_ERROR));
    rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'零面積貼圖三角形不能冒充已有真實貼面');
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$texturedBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($quality,JSON_THROW_ON_ERROR));
    rejects(fn()=>$pilotApp->registerResult(array_replace($pilotRegistration,['manifest_path'=>'../pilot-state/app.sqlite'])),'manifest路徑不可跨artifact root');
    $partial=$pilotApp->registerResult($pilotRegistration);
    $pilotStatus=$pilotApp->job($pilotJob['id']);
    check($pilotStatus['partial']===1 && $pilotStatus['completed']===0 && $pilotStatus['status']==='partial','部分貼皮不能增加完成數');
    check($partial['model']['texture_status']==='partial' && $partial['model']['surfaces_textured']===1 && $partial['model']['forward_axis']==='-Z','model保留品質與定位軸');
    check($partial['model']['surfaces'][0]['geometric_coverage']===0.5 && $partial['model']['walls_total']===1 && $partial['model']['surfaces_total']===2,'分段共用wall key按唯一面計數且底面不計品質');
    check(($partial['model']['wall_quality'][0]['flags'] ?? null)===['SINGLE_VIEW','UNVERIFIED'] && ($partial['model']['engine_quality']['warnings'][0] ?? null)==='GLOBAL_REGISTRATION','A級紋理仍須保留未驗證／單視角等品質警示');
    check(($partial['model']['wall_quality'][0]['hole_fraction'] ?? null)===0.015625 && ($partial['model']['wall_quality'][0]['fuse_mode'] ?? null)==='single' && ($partial['model']['wall_quality'][0]['texture_source'] ?? null)==='raw-single-view-no-fill' && ($partial['model']['wall_quality'][0]['engine_observed_share'] ?? null)===1 && ($partial['model']['verification_status'] ?? null)==='trial_unverified','品質摘要保留補洞／融合／原始貼圖來源與待核對狀態');
    check(!isset($partial['model']['wall_quality'][0]['token']) && !isset($partial['model']['wall_quality'][0]['plane']['path']) && !isset($partial['model']['engine_quality']['path']) && !isset($partial['model']['engine_quality']['registration']['token']),'品質摘要不可將額外路徑或機密欄位公開');
    $pilotSelection=$pilotApp->selection($polygon);
    check($pilotSelection['features'][0]['properties']['status']==='queued' && $pilotSelection['features'][0]['properties']['model']['texture_status']==='partial','部分成果可載入且來源維持queued');
    $pilotOverlap=$pilotApp->createJob('部分成果重疊',$polygon);
    check($pilotOverlap['completed']===0 && $pilotOverlap['skipped']===0 && $pilotOverlap['items'][0]['status']==='queued','新任務不可將partial當完成重用');
    $pilotApp->runBatch(100);
    check($pilotApp->job($pilotOverlap['id'])['completed']===0,'worker不可把部分成果當成完成');
    $pilotApp->cancelJob($pilotJob['id']); $pilotApp->retryJob($pilotJob['id']);
    check(is_file($pilotApp->modelFile($partial['asset_id'])['path']) && $pilotApp->job($pilotJob['id'])['items'][0]['status']==='queued','取消重試保留部分成果並重新準備');
    $pilotApp->runBatch(100); $pilotApp->retryJob($pilotJob['id']); $pilotApp->runBatch(100);
    check($pilotApp->job($pilotJob['id'])['partial']===1 && $pilotApp->job($pilotJob['id'])['completed']===0,'partial可重試且仍不假完成');
    $pilotZip=$pilotApp->export($polygon); $zip->open($pilotZip);
    $pilotManifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
    check($pilotManifest['partial']===1 && $pilotManifest['completed']===0 && $pilotManifest['missing']===1 && $pilotManifest['items'][0]['model']['texture_status']==='partial','ZIP保留partial品質與真實完成數');
    $zip->close(); unlink($pilotZip);
    $allTextured=$texturedDocument;
    $allTextured['meshes'][0]['primitives'][2]['attributes']['TEXCOORD_0']=1;
    $allTextured['meshes'][0]['primitives'][2]['material']=0;
    $allTextured['meshes'][0]['primitives'][2]['extras']['textured']=true;
    $allTexturedBytes=encodeGlb($allTextured,$texturedBinary); $allTexturedQuality=$quality;
    $allTexturedQuality['model_sha256']=hash('sha256',$allTexturedBytes); $allTexturedQuality['surfaces_textured']=2;
    $allTexturedQuality['surfaces'][1]['textured']=true; $allTexturedQuality['surfaces'][1]['geometric_coverage']=1; $allTexturedQuality['surfaces'][1]['coverage']=1;
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$allTexturedBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($allTexturedQuality,JSON_THROW_ON_ERROR));
    $stillPartial=$pilotApp->registerResult($pilotRegistration);
    check($stillPartial['model']['surfaces_textured']===2 && $stillPartial['model']['texture_status']==='partial','每面都有一塊貼圖仍不代表覆蓋率足夠完成');
    $allTexturedQuality['texture_status']='complete';
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($allTexturedQuality,JSON_THROW_ON_ERROR));
    rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'未達95%覆蓋率不可標全貼皮完成');
    array_splice($allTextured['meshes'][0]['primitives'],1,1);
    $coveredBytes=encodeGlb($allTextured,$texturedBinary); $coveredQuality=$allTexturedQuality;
    $coveredQuality['surfaces'][0]['area_m2']=0.5; $coveredQuality['surfaces'][0]['geometric_coverage']=1; $coveredQuality['surfaces'][0]['coverage']=1;
    $coveredQuality['model_sha256']=hash('sha256',$coveredBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.glb',$coveredBytes);
    file_put_contents($pilotConfig['artifact_dir'].'/incoming/model.manifest.json',json_encode($coveredQuality,JSON_THROW_ON_ERROR));
    rejects(fn()=>$pilotApp->registerResult($pilotRegistration),'待核對試轉成果即使覆蓋足夠也不能標完成');
    echo "PASS app: $checks checks\n";
} catch (Throwable $testError) {
    $failureMessage=(string)$testError;
    unset($testError);
} finally {
    unset($app,$other,$large,$missing,$failureApp,$pilotApp);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($tmp);
}
if (isset($failureMessage)) { fwrite(STDERR,$failureMessage.PHP_EOL); exit(1); }
