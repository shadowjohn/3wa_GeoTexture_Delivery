<?php
declare(strict_types=1);
namespace GeoTexture;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SceneJobs {
    public function __construct(private PDO $db, private App $app, private string $root, private string $python) {
        $db->exec("CREATE TABLE IF NOT EXISTS scene_jobs(id TEXT PRIMARY KEY,kind TEXT NOT NULL,request_json TEXT NOT NULL,status TEXT NOT NULL,progress INTEGER NOT NULL,phase TEXT NOT NULL,error TEXT,output_json TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)");
    }
    private function sql(string $sql,array $values=[]): \PDOStatement { $q=$this->db->prepare($sql);$q->execute($values);return $q; }
    private function json(mixed $value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    private function row(string $id): array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) { throw new InvalidArgumentException('區塊 id 無效'); }
        $r=$this->sql('SELECT * FROM scene_jobs WHERE id=?',[$id])->fetch();
        if (!$r) { throw new AppException('找不到區塊工作',404); }return $r;
    }
    public function status(string $id): array {
        $r=$this->row($id);$request=json_decode($r['request_json'],true,512,JSON_THROW_ON_ERROR);
        $result=['id'=>$id,'kind'=>$r['kind'],'status'=>$r['status'],'progress'=>(int)$r['progress'],'phase'=>$r['phase'],
            'total'=>count($request['entries']),'error'=>$r['error'],'members'=>array_column($request['entries'],'asset_id')];
        if ($r['status']==='completed') {
            $output=json_decode($r['output_json'],true,512,JSON_THROW_ON_ERROR);
            $url='api.php?action=scene_file&id='.$id;
            $result['model']=['asset_id'=>'scene-'.$id,'url'=>$url.'&format=glb','longitude'=>$request['origin']['longitude'],
                'latitude'=>$request['origin']['latitude'],'altitude'=>0,'heading'=>0,'scale'=>1,'up_axis'=>'Y','forward_axis'=>'-Z',
                'appearance_kind'=>'procedural','verification_status'=>'synthetic','texture_status'=>'complete'];
            $result['stats']=$output['stats'];
            if ($r['kind']==='export') { $result['download_url']=$url.'&format=zip';$result['glb_url']=$url.'&format=glb'; }
        }return $result;
    }
    private function entries(array $features,string $recipe): array {
        $entries=[];
        foreach ($features as $feature) {
            $p=$feature['properties'];$model=$p['model'] ?? null;
            if (!$model) { continue; }
            $file=$this->app->modelFile($model['asset_id']);
            $entries[]=['asset_id'=>$model['asset_id'],'path'=>$file['path'],'sha256'=>$file['sha256'],
                'source_key'=>$p['source_key'],'build_id'=>(string)$p['build_id'],'source_hash'=>$p['source_hash'],
                'recipe_key'=>$recipe,'longitude'=>$model['longitude'],'latitude'=>$model['latitude']];
        }
        usort($entries,fn($a,$b)=>[$a['source_key'],$a['build_id']]<=>[$b['source_key'],$b['build_id']]);return $entries;
    }
    private function enqueue(array $request,bool $retry=false): array {
        // Paths are private implementation details, never part of identity or API output.
        $identity=$request;foreach ($identity['entries'] as &$entry) { unset($entry['path']); }unset($entry);
        $id=hash('sha256',$this->json($identity));$request['scene_id']=$id;$now=gmdate('c');
        $encoded=$this->json($request);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $existing=$this->sql('SELECT status,phase FROM scene_jobs WHERE id=?',[$id])->fetch();
            $queue=!$existing || ($retry && in_array($existing['status'],['failed','cancelled'],true))
                || ($request['kind']==='preview' && $existing['status']==='cancelled' && $existing['phase']==='expired');
            if($queue) {
                if((int)$this->sql("SELECT COUNT(*) FROM scene_jobs WHERE status IN ('queued','running')")->fetchColumn()>=100){throw new AppException('區塊工作排隊中，請稍後重試',429);}
                if(!$existing){$this->sql("INSERT INTO scene_jobs VALUES(?,?,?,'queued',0,'queued',NULL,NULL,?,?)",[$id,$request['kind'],$encoded,$now,$now]);}
                else{$this->sql("UPDATE scene_jobs SET status='queued',progress=0,phase='queued',error=NULL,output_json=NULL,updated_at=? WHERE id=?",[$now,$id]);}
            }
            $this->db->exec('COMMIT');
        }catch(Throwable $error){$this->db->exec('ROLLBACK');throw $error;}
        return $this->status($id);
    }
    public function export(array $polygon,array $collection,string $recipe): array {
        if (!Recipes::procedural($recipe)) { throw new InvalidArgumentException('整區 GLB 目前支援三種示意立面風格'); }
        if (count($collection['features'])>2000) { throw new InvalidArgumentException('整區 GLB 最多 2000 棟，請縮小範圍'); }
        $entries=$this->entries($collection['features'],$recipe);
        if (!$entries) { throw new InvalidArgumentException('範圍內尚無此風格的完成模型，請先建立轉換任務'); }
        $bbox=Geometry::bbox($polygon);
        return $this->enqueue(['version'=>1,'kind'=>'export','recipe_key'=>$recipe,
            'origin'=>['longitude'=>($bbox[0]+$bbox[2])/2,'latitude'=>($bbox[1]+$bbox[3])/2],
            'entries'=>$entries,'polygon'=>$polygon,'collection'=>$collection],true);
    }
    private static function xy(float $lon,float $lat): array { return [6378137*deg2rad($lon),6378137*log(tan(M_PI/4+deg2rad($lat)/2))]; }
    private static function ll(float $x,float $y): array { return [rad2deg($x/6378137),rad2deg(2*atan(exp($y/6378137))-M_PI/2)]; }
    public function preview(array $bbox,string $recipe,?array $center=null): array {
        if (!Recipes::procedural($recipe)) { return ['scenes'=>[],'recipe_key'=>$recipe,'limit'=>8]; }
        BuildingSource::validateBbox($bbox);
        if (max(abs($bbox[1]),abs($bbox[3]))>85) { throw new InvalidArgumentException('區塊預覽緯度超出範圍'); }
        if($center!==null && (!array_is_list($center) || count($center)!==2 || !is_numeric($center[0]) || !is_numeric($center[1]) || !is_finite((float)$center[0]) || !is_finite((float)$center[1]) || $center[0]<$bbox[0] || $center[0]>$bbox[2] || $center[1]<$bbox[1] || $center[1]>$bbox[3])){throw new InvalidArgumentException('預覽中心必須位於查詢範圍內');}
        [$west,$south]=self::xy($bbox[0],$bbox[1]);[$east,$north]=self::xy($bbox[2],$bbox[3]);
        $cx=($west+$east)/512;$cy=($south+$north)/512;$cells=[];
        if($center!==null){[$fx,$fy]=self::xy((float)$center[0],(float)$center[1]);$cx=$fx/256;$cy=$fy/256;}
        // Only the nearest cells are candidates; do not scan a whole city for a preview.
        for($x=max((int)floor($west/256),(int)floor($cx)-4);$x<=min((int)floor($east/256),(int)floor($cx)+4);$x++) {
            for($y=max((int)floor($south/256),(int)floor($cy)-4);$y<=min((int)floor($north/256),(int)floor($cy)+4);$y++) { $cells[]=[$x,$y]; }
        }
        usort($cells,fn($a,$b)=>(($a[0]+.5-$cx)**2+($a[1]+.5-$cy)**2)<=>(($b[0]+.5-$cx)**2+($b[1]+.5-$cy)**2));
        $scenes=[];
        foreach(array_slice($cells,0,8) as [$x,$y]) {
            $bounds=[...self::ll($x*256,$y*256),...self::ll(($x+1)*256,($y+1)*256)];
            $features=$this->app->buildings($bounds,2000,true)['features'];
            $features=array_values(array_filter($features,static function($f) use($x,$y) {
                $m=$f['properties']['model'] ?? null;if(!$m){return false;}
                [$a,$b]=self::xy((float)$m['longitude'],(float)$m['latitude']);return (int)floor($a/256)===$x && (int)floor($b/256)===$y;
            }));
            usort($features,fn($a,$b)=>strcmp((string)$a['properties']['build_id'],(string)$b['properties']['build_id']));
            foreach(array_chunk($features,32) as $group) {
                if(count($group)<2){continue;}
                [$lon,$lat]=self::ll(($x+.5)*256,($y+.5)*256);
                $scenes[]=$this->enqueue(['version'=>1,'kind'=>'preview','recipe_key'=>$recipe,'origin'=>['longitude'=>$lon,'latitude'=>$lat],'entries'=>$this->entries($group,$recipe)]);
                if(count($scenes)===8){break 2;}
            }
        }return ['scenes'=>$scenes,'recipe_key'=>$recipe,'limit'=>8];
    }
    public function cancel(string $id): array {
        $row=$this->row($id);if($row['kind']!=='export'){throw new InvalidArgumentException('只能取消匯出工作');}
        $this->sql("UPDATE scene_jobs SET status='cancelled',phase='cancelled',updated_at=? WHERE id=? AND status IN ('queued','running')",[gmdate('c'),$id]);return $this->status($id);
    }
    public function file(string $id,string $format): array {
        if(!in_array($format,['glb','zip'],true)){throw new InvalidArgumentException('成果格式無效');}
        $r=$this->row($id);$out=json_decode($r['output_json'] ?? '{}',true);
        if($r['status']!=='completed' || !isset($out[$format])){throw new AppException('成果尚未完成',404);}
        $request=json_decode($r['request_json'],true,512,JSON_THROW_ON_ERROR);
        try {
            foreach($request['entries'] as $entry) {
                $source=$this->app->modelFile($entry['asset_id']);
                if(!hash_equals($entry['sha256'],$source['sha256'])){throw new RuntimeException('凍結來源 SHA 不符');}
            }
        } catch(Throwable $error) {
            $this->sql("UPDATE scene_jobs SET status='failed',phase='failed',error='原始成果已失效，請重新產生後匯出' WHERE id=? AND status='completed'",[$id]);
            throw new AppException('原始成果不存在或已損壞，請重新產生後匯出',404);
        }
        $path=$this->root.'/'.$id.'.'.$format;
        if(!is_file($path) || !hash_equals($out[$format],hash_file('sha256',$path))){
            $this->sql("UPDATE scene_jobs SET status='failed',phase='failed',error='區塊成果已損壞，請重新匯出' WHERE id=? AND status='completed'",[$id]);
            throw new AppException('區塊成果不存在或已損壞，請重新匯出',404);
        }
        return ['path'=>$path,'name'=>'geotexture-'.$id.'.'.$format,'sha256'=>$out[$format]];
    }
    // Caller owns the shared worker flock; a running row here is an interrupted worker.
    public function runOne(): bool {
        $this->sql("UPDATE scene_jobs SET status='queued',phase='queued',progress=0 WHERE status='running'");
        $this->sql("UPDATE scene_jobs SET status='cancelled',phase='expired' WHERE kind='preview' AND status='queued' AND updated_at<?",[gmdate('c',time()-900)]);
        $row=$this->sql("SELECT * FROM scene_jobs WHERE status='queued' ORDER BY CASE kind WHEN 'export' THEN 0 ELSE 1 END,created_at,rowid LIMIT 1")->fetch();
        if(!$row){return false;}$id=$row['id'];$work=null;
        $this->sql("UPDATE scene_jobs SET status='running',phase='loading',updated_at=? WHERE id=? AND status='queued'",[gmdate('c'),$id]);
        try {
            if(!is_dir($this->root) && !mkdir($this->root,0755,true)){throw new RuntimeException('無法建立區塊目錄');}
            $work=$this->root.'/.work-'.$id.'-'.bin2hex(random_bytes(4));mkdir($work,0755);chmod($work,0755);
            $request=json_decode($row['request_json'],true,512,JSON_THROW_ON_ERROR);
            foreach($request['entries'] as &$entry){$file=$this->app->modelFile($entry['asset_id']);if(!hash_equals($entry['sha256'],$file['sha256'])){throw new RuntimeException('來源模型版本已改變');}$entry['path']=$file['path'];}unset($entry);
            file_put_contents($work.'/input.json',$this->json(array_intersect_key($request,array_flip(['version','scene_id','kind','recipe_key','origin','entries']))));
            $lastProgress='';
            $tick=function(?array $event) use($id,&$lastProgress): void {
                if($this->sql('SELECT status FROM scene_jobs WHERE id=?',[$id])->fetchColumn()!=='running'){throw new RuntimeException('區塊工作已取消');}
                if($event===null){return;}$phase=$event['phase'] ?? '';$fraction=min(1,max(0,($event['done'] ?? 0)/max(1,$event['total'] ?? 1)));
                $progress=match($phase){'loading'=>(int)($fraction*20),'packing'=>20+(int)($fraction*65),'writing'=>85+(int)($fraction*5),default=>0};
                if($lastProgress===$phase.$progress){return;}$lastProgress=$phase.$progress;
                $this->sql("UPDATE scene_jobs SET progress=MAX(progress,?),phase=?,updated_at=? WHERE id=? AND status='running'",[$progress,$phase,gmdate('c'),$id]);
            };
            ProceduralRunner::scene($work.'/input.json',$work.'/scene.glb',$this->python,$tick);
            $tick(null);$this->app->validateScene($work.'/scene.glb');
            $manifest=json_decode(file_get_contents($work.'/scene.manifest.json'),true,512,JSON_THROW_ON_ERROR);
            $hash=hash_file('sha256',$work.'/scene.glb');
            if(($manifest['scene_id'] ?? null)!==$id || ($manifest['model_sha256'] ?? null)!==$hash || ($manifest['members'] ?? null)!==array_column($request['entries'],'asset_id')){throw new RuntimeException('區塊 manifest 驗證失敗');}
            $output=['glb'=>$hash,'stats'=>$manifest['stats']];
            if($row['kind']==='export'){$this->package($request,$manifest,$work,$tick);$output['zip']=hash_file('sha256',$work.'/scene.zip');}
            $tick(null);
            foreach(array_keys($output) as $format){if($format==='stats'){continue;}if(!rename($work.'/scene.'.$format,$this->root.'/'.$id.'.'.$format)){throw new RuntimeException('無法發佈區塊檔案');}}
            $this->sql("UPDATE scene_jobs SET status='completed',progress=100,phase='completed',output_json=?,error=NULL,updated_at=? WHERE id=? AND status='running'",[$this->json($output),gmdate('c'),$id]);
        }catch(Throwable $e){
            error_log('[GeoTexture scene] '.$id.' '.$e->getMessage());
            $this->sql("UPDATE scene_jobs SET status='failed',phase='failed',error=?,updated_at=? WHERE id=? AND status='running'",[substr($e->getMessage(),0,500),gmdate('c'),$id]);
        }finally{if($work && is_dir($work)){foreach(glob($work.'/*') as $p){unlink($p);}rmdir($work);}}
        return true;
    }
    private function package(array $request,array $scene,string $work,callable $tick): void {
        $zip=new ZipArchive();if($zip->open($work.'/scene.zip',ZipArchive::CREATE)!==true){throw new RuntimeException('無法建立區塊 ZIP');}
        $manifest=['version'=>1,'recipe_key'=>$request['recipe_key'],'total'=>count($request['collection']['features']),'completed'=>count($request['entries']),'missing'=>count($request['collection']['features'])-count($request['entries']),'partial'=>0,'items'=>[]];
        $entries=array_column($request['entries'],null,'asset_id');
        try {
            foreach($request['collection']['features'] as $feature){
                $tick(null);$p=$feature['properties'];$model=$p['model'] ?? null;
                $item=['source_key'=>$p['source_key'],'build_id'=>$p['build_id'],'source_hash'=>$p['source_hash'],'recipe_key'=>$request['recipe_key'],'status'=>$model?'completed':'missing','model'=>null];
                if($model){$entry=$entries[$model['asset_id']];$name='models/'.$entry['asset_id'].'.glb';if(!$zip->addFile($entry['path'],$name)){throw new RuntimeException('無法加入原始模型');}unset($model['url']);$item['model']=['path'=>$name,'sha256'=>$entry['sha256']]+$model;}
                else{$item['reason']='匯出建立時尚無此版本的完成模型';}$manifest['items'][]=$item;
            }
            foreach(['selection.geojson'=>['type'=>'Feature','geometry'=>$request['polygon'],'properties'=>['area_m2'=>Geometry::area($request['polygon'])]],'buildings.geojson'=>$request['collection'],'manifest.json'=>$manifest,'scene/manifest.json'=>$scene] as $name=>$value){if(!$zip->addFromString($name,$this->json($value))){throw new RuntimeException('無法加入匯出說明');}}
            if(!$zip->addFile($work.'/scene.glb','scene/whole_block.glb')){throw new RuntimeException('無法加入整區 GLB');}
        }finally{if(!$zip->close()){throw new RuntimeException('區塊 ZIP 寫入失敗');}}
    }
}
