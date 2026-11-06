<?php
declare(strict_types=1);
namespace GeoTexture;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class App {
    private PDO $db;
    private ?BuildingSource $source = null;
    private ?string $sourceError = null;
    private string $stateDir;
    private string $artifactDir;
    private string $recipeKey;
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
        $this->stateDir = rtrim($config['state_dir'], '/\\');
        $this->artifactDir = rtrim($config['artifact_dir'] ?? $this->stateDir . '/artifacts', '/\\');
        $this->recipeKey = Recipes::validate($config['recipe_key'] ?? 'texture-v1');
        $this->directory($this->stateDir);
        $this->directory($this->artifactDir);
        $dbPath=$this->stateDir.'/app.sqlite';
        if (is_file($dbPath)) { @chmod($dbPath,0660); }
        $this->db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        @chmod($dbPath,0660);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->schema();
        try { $this->source = new BuildingSource($config['source_db']); }
        catch (Throwable $e) { $this->sourceError = '建物來源無法開啟：' . $e->getMessage(); }
    }

    private function schema(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS jobs (
            id TEXT PRIMARY KEY,name TEXT NOT NULL,polygon_json TEXT NOT NULL,area_m2 REAL NOT NULL,
            total INTEGER NOT NULL,force INTEGER NOT NULL,recipe_key TEXT NOT NULL,status TEXT NOT NULL,
            created_at TEXT NOT NULL,updated_at TEXT NOT NULL,error TEXT
        );
        CREATE TABLE IF NOT EXISTS job_items (
            job_id TEXT NOT NULL REFERENCES jobs(id),build_id TEXT NOT NULL,source_key TEXT NOT NULL,
            source_hash TEXT NOT NULL,recipe_key TEXT NOT NULL,feature_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued',asset_id TEXT,prepared_at TEXT,reused INTEGER NOT NULL DEFAULT 0,error TEXT,
            PRIMARY KEY(job_id,source_key,build_id)
        );
        CREATE INDEX IF NOT EXISTS job_items_version ON job_items(source_key,build_id,source_hash,recipe_key);
        CREATE TABLE IF NOT EXISTS assets (
            asset_id TEXT PRIMARY KEY,source_key TEXT NOT NULL,build_id TEXT NOT NULL,source_hash TEXT NOT NULL,
            recipe_key TEXT NOT NULL,relative_path TEXT NOT NULL,size INTEGER NOT NULL,sha256 TEXT NOT NULL,
            model_json TEXT NOT NULL,created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS assets_version ON assets(source_key,build_id,source_hash,recipe_key,created_at);");
    }

    public function meta(): array {
        try { $meta = $this->source?->meta(); }
        catch (Throwable $e) { $meta = null; $this->sourceError = $e->getMessage(); }
        return [
            'source' => ['ready' => $meta !== null && ($meta['ready'] ?? true), 'name' => $meta['name'] ?? '新北市建物 8418', 'error' => $this->sourceError],
            'center' => $this->config['center'] ?? ['longitude' => 121.41798, 'latitude' => 25.175544],
            'zoom' => $this->config['zoom'] ?? 15, 'converter_ready' => false, 'csrf_token' => null,
            'recipes' => Recipes::all(), 'recipe_key' => $this->recipeKey,
        ];
    }

    private function source(): BuildingSource {
        if (!$this->source) { throw new AppException($this->sourceError ?? '建物來源尚未設定', 503); }
        return $this->source;
    }

    public function buildings(array $bbox, int $limit = 2000, bool $preview = false): array {
        if ($limit < 1 || $limit > 10000) { throw new InvalidArgumentException('limit 必須介於 1 到 10000'); }
        return $this->decorate($this->source()->query($bbox, $limit), $preview);
    }

    public function selection(array $polygon): array {
        $polygon = Geometry::validatePolygon($polygon);
        return $this->decorate($this->source()->query(Geometry::bbox($polygon), 10000, $polygon));
    }

    private function completeSelection(array $polygon): array {
        $collection = $this->source()->query(Geometry::bbox($polygon), 10000, $polygon);
        if (!empty($collection['meta']['truncated'])) { throw new AppException('範圍超過 10000 棟上限，請縮小 Polygon 後重試；尚未儲存部分清單。', 422); }
        if (count($collection['features']) === 0) { throw new AppException('範圍內沒有建物，請重新繪製 Polygon。', 422); }
        return $collection;
    }

    public function createJob(string $name, array $polygon, bool $force = false): array {
        $name = trim($name);
        if ($name === '' || preg_match_all('/./us',$name) > 120) { throw new InvalidArgumentException('任務名稱必填且不可超過 120 字'); }
        $polygon = Geometry::validatePolygon($polygon);
        $collection = $this->completeSelection($polygon);
        $id = bin2hex(random_bytes(12));
        $now = $this->now();
        $this->db->beginTransaction();
        try {
            $this->statement('INSERT INTO jobs VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$id,$name,$this->json($polygon),Geometry::area($polygon),count($collection['features']),(int)$force,$this->recipeKey,'queued',$now,$now,null]);
            $insert = $this->db->prepare('INSERT INTO job_items(job_id,build_id,source_key,source_hash,recipe_key,feature_json,status,asset_id,reused) VALUES(?,?,?,?,?,?,?,?,?)');
            foreach ($collection['features'] as $feature) {
                $p = $feature['properties'];
                $asset = $force ? null : $this->validAsset($p['source_key'],(string)$p['build_id'],$p['source_hash'],$this->recipeKey);
                $complete=$asset && !$this->isPartial($asset);
                $insert->execute([$id,(string)$p['build_id'],$p['source_key'],$p['source_hash'],$this->recipeKey,$this->json($feature),$complete ? 'completed' : 'queued',$asset['asset_id'] ?? null,$complete ? 1 : 0]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return $this->job($id);
    }

    public function jobs(): array {
        $rows = $this->db->query('SELECT id FROM jobs ORDER BY created_at DESC,rowid DESC')->fetchAll();
        return array_map(fn(array $r) => $this->job($r['id'], false), $rows);
    }

    public function progress(): array {
        // 輪詢只聚合資料庫進度，不逐棟讀取／驗證 GLB，也不傳回完整幾何。
        $jobs=$this->rows("SELECT j.id,j.status,j.total,j.recipe_key,j.updated_at,
            SUM(CASE WHEN i.prepared_at IS NOT NULL OR i.status IN ('completed','partial') THEN 1 ELSE 0 END) AS prepared,
            SUM(CASE WHEN i.status='completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN i.status='partial' THEN 1 ELSE 0 END) AS partial,
            SUM(CASE WHEN i.status='failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN i.status='queued' THEN 1 ELSE 0 END) AS queued,
            SUM(CASE WHEN i.status='completed' AND i.prepared_at IS NULL THEN 1 ELSE 0 END) AS skipped
            FROM jobs j LEFT JOIN job_items i ON i.job_id=j.id
            GROUP BY j.id ORDER BY j.created_at DESC,j.rowid DESC",[]);
        foreach ($jobs as &$job) {
            foreach (['total','prepared','completed','partial','failed','queued','skipped'] as $key) { $job[$key]=(int)$job[$key]; }
        }
        unset($job);
        $running=false;
        $path=$this->stateDir.'/worker.lock';
        $lock=is_file($path) ? @fopen($path,'r') : false;
        if ($lock) {
            $wouldBlock=0;
            if (flock($lock,LOCK_EX|LOCK_NB,$wouldBlock)) { flock($lock,LOCK_UN); }
            else { $running=(bool)$wouldBlock; }
            fclose($lock);
        }
        return ['jobs'=>$jobs,'worker_running'=>$running,'checked_at'=>gmdate('c')];
    }

    public function job(string $id, bool $withItems = true): array {
        $job = $this->jobRow($id);
        $rows = $this->rows('SELECT * FROM job_items WHERE job_id=? ORDER BY rowid', [$id]);
        $counts = ['prepared'=>0,'completed'=>0,'partial'=>0,'failed'=>0,'skipped'=>0];
        $queued = 0;
        $items = [];
        foreach ($rows as $row) {
            $asset = $row['asset_id'] ? $this->assetById($row['asset_id']) : null;
            if ($asset && ($asset['source_key'] !== $row['source_key'] || $asset['build_id'] !== $row['build_id'] || $asset['source_hash'] !== $row['source_hash'] || $asset['recipe_key'] !== $row['recipe_key'])) { $asset = null; }
            if (!$asset && !(bool)$job['force'] && $job['status'] !== 'cancelled') {
                $asset = $this->validAsset($row['source_key'],$row['build_id'],$row['source_hash'],$row['recipe_key']);
            }
            $status = $row['status'];
            if ($asset && !$this->isPartial($asset)) { $status = 'completed'; }
            elseif ($asset && !in_array($status,['queued','failed'],true)) { $status = 'partial'; }
            elseif (!$asset && (in_array($status,['completed','partial'],true) || ($status==='prepared' && Recipes::procedural($row['recipe_key'])))) {
                $status = Recipes::procedural($row['recipe_key']) ? 'queued' : ($row['prepared_at'] ? 'prepared' : 'queued');
            }
            if ($status !== $row['status'] || ($asset['asset_id'] ?? null) !== $row['asset_id']) {
                $this->statement('UPDATE job_items SET status=?,asset_id=?,reused=? WHERE job_id=? AND source_key=? AND build_id=?',[$status,$asset['asset_id'] ?? null,$asset && !$this->isPartial($asset) && !$row['prepared_at'] ? 1 : 0,$id,$row['source_key'],$row['build_id']]);
            }
            if ($row['prepared_at'] || in_array($status,['completed','partial'],true)) { $counts['prepared']++; }
            if ($status === 'completed') { $counts['completed']++; if (!$row['prepared_at']) { $counts['skipped']++; } }
            if ($asset && $this->isPartial($asset)) { $counts['partial']++; }
            if ($status === 'failed') { $counts['failed']++; }
            if ($status === 'queued') { $queued++; }
            if ($withItems) {
                $feature = json_decode($row['feature_json'],true,512,JSON_THROW_ON_ERROR);
                $items[] = ['build_id'=>$row['build_id'],'source_key'=>$row['source_key'],'source_hash'=>$row['source_hash'],'recipe_key'=>$row['recipe_key'],'name'=>$feature['properties']['name'] ?? $row['build_id'],'height'=>$feature['properties']['height'] ?? null,'height_missing'=>$feature['properties']['height_missing'] ?? true,'status'=>$status,'error'=>$row['error'],'model'=>$asset ? $this->model($asset) : null];
            }
        }
        $status = $job['status'];
        if ($status !== 'cancelled') {
            if ($counts['completed'] === (int)$job['total']) { $status = 'completed'; }
            elseif ($queued > 0) { $status = $counts['prepared'] > 0 ? 'preparing' : 'queued'; }
            elseif ($counts['failed'] > 0) { $status = 'failed'; }
            elseif ($counts['partial'] > 0) { $status = 'partial'; }
            else { $status = 'awaiting_converter'; }
        }
        $error = null;
        foreach ($rows as $row) { if ($row['status'] === 'failed' && $row['error']) { $error = $row['error']; break; } }
        if ($status !== $job['status'] || $error !== $job['error']) {
            $job['updated_at'] = $this->now();
            $changed=$this->statement('UPDATE jobs SET status=?,error=?,updated_at=? WHERE id=? AND status=?', [$status,$error,$job['updated_at'],$id,$job['status']])->rowCount();
            if (!$changed) { return $this->job($id,$withItems); }
        }
        // 取消可能與成果檢查併發，讀取不能覆蓋已提交的取消狀態。
        if ($this->jobRow($id)['status'] === 'cancelled') { $status='cancelled'; }
        $result = ['id'=>$id,'name'=>$job['name'],'polygon'=>json_decode($job['polygon_json'],true,512,JSON_THROW_ON_ERROR),'area_m2'=>(float)$job['area_m2'],'total'=>(int)$job['total']] + $counts + ['status'=>$status,'force'=>(bool)$job['force'],'recipe_key'=>$job['recipe_key'],'created_at'=>$job['created_at'],'updated_at'=>$job['updated_at'],'error'=>$error];
        if ($withItems) { $result['items'] = $items; }
        return $result;
    }

    private function jobRow(string $id): array {
        $rows = $this->rows('SELECT * FROM jobs WHERE id=?',[$id]);
        if (!$rows) { throw new AppException('找不到任務',404); }
        return $rows[0];
    }

    public function cancelJob(string $id): array {
        $this->jobRow($id);
        $this->statement("UPDATE jobs SET status='cancelled',updated_at=? WHERE id=?",[$this->now(),$id]);
        return $this->job($id);
    }

    public function retryJob(string $id): array {
        $job = $this->job($id,false);
        if (!in_array($job['status'],['failed','cancelled','awaiting_converter','partial'],true)) { throw new AppException('此任務目前不需要重試',409); }
        $this->db->beginTransaction();
        try {
            $this->statement("UPDATE job_items SET status='queued',error=NULL WHERE job_id=? AND status='failed'",[$id]);
            $this->statement("UPDATE job_items SET status='queued',prepared_at=NULL,error=NULL WHERE job_id=? AND status='partial'",[$id]);
            $this->statement("UPDATE jobs SET status='queued',error=NULL,updated_at=? WHERE id=?",[$this->now(),$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return $this->job($id);
    }

    public function runBatch(int $batch = 100, ?string $jobId = null, float $timeBudget = 0): array {
        if ($batch < 1 || $batch > 10000) { throw new InvalidArgumentException('batch 必須介於 1 到 10000'); }
        if ($jobId!==null) { $this->jobRow($jobId); }
        $lock = fopen($this->stateDir . '/worker.lock','c');
        if (!$lock) { throw new RuntimeException('無法開啟 worker lock'); }
        $stats = ['locked'=>false,'processed'=>0,'prepared'=>0,'failed'=>0,'completed'=>0,'converter_ready'=>false];
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); $stats['locked']=true; return $stats; }
        try {
            // 先撤回遺失成果的完成狀態，再取有限批次；來源清單已在建立任務時凍結。
            $this->jobs();
            $rows = $this->rows("SELECT i.* FROM job_items i JOIN jobs j ON j.id=i.job_id WHERE i.status='queued' AND j.status IN ('queued','preparing')".($jobId!==null ? ' AND j.id=?' : '')." ORDER BY j.created_at,j.rowid,i.rowid LIMIT ?",$jobId!==null ? [$jobId,$batch] : [$batch]);
            $started=microtime(true);
            foreach ($rows as $row) {
                if ($timeBudget>0 && microtime(true)-$started>=$timeBudget) { break; }
                if (!in_array($this->jobRow($row['job_id'])['status'],['queued','preparing'],true)) { continue; }
                try {
                    $inputPath=$this->prepareInput($row);
                    if (Recipes::procedural($row['recipe_key'])) {
                        $this->generateProcedural($row,$inputPath);
                    }
                    $changed = $this->statement("UPDATE job_items SET status=CASE WHEN status='queued' THEN 'prepared' ELSE status END,prepared_at=?,error=NULL WHERE job_id=? AND source_key=? AND build_id=? AND status IN ('queued','completed') AND EXISTS(SELECT 1 FROM jobs j WHERE j.id=job_id AND j.status<>'cancelled')",[$this->now(),$row['job_id'],$row['source_key'],$row['build_id']])->rowCount();
                    $stats['prepared'] += $changed;
                    if (Recipes::procedural($row['recipe_key'])) { $stats['completed']+=$changed; }
                } catch (Throwable $e) {
                    $changed = $this->statement("UPDATE job_items SET status='failed',error=? WHERE job_id=? AND source_key=? AND build_id=? AND status='queued' AND EXISTS(SELECT 1 FROM jobs j WHERE j.id=job_id AND j.status<>'cancelled')",[$e->getMessage(),$row['job_id'],$row['source_key'],$row['build_id']])->rowCount();
                    $stats['failed'] += $changed;
                    error_log('[GeoTexture worker] '.$row['job_id'].'/'.$row['build_id'].': '.$e->getMessage());
                }
                $stats['processed'] += $changed;
                $this->statement('UPDATE jobs SET updated_at=? WHERE id=?',[$this->now(),$row['job_id']]);
                $this->job($row['job_id'],false);
            }
            return $stats;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function prepareInput(array $row): string {
        $dir = $this->stateDir . '/inputs';
        $this->directory($dir);
        $key = hash('sha256',$this->json([$row['source_key'],$row['build_id'],$row['source_hash'],$row['recipe_key']]));
        $target = $dir . '/' . $key . '.json';
        $input = ['source_key'=>$row['source_key'],'build_id'=>$row['build_id'],'source_hash'=>$row['source_hash'],'recipe_key'=>$row['recipe_key'],'feature'=>json_decode($row['feature_json'],true,512,JSON_THROW_ON_ERROR)];
        $temp = tempnam($dir,'input-');
        if (!$temp) { throw new RuntimeException('無法建立前置資料暫存檔'); }
        try {
            if (file_put_contents($temp,$this->json($input),LOCK_EX) === false || !rename($temp,$target)) { throw new RuntimeException('無法儲存前置資料'); }
        } finally { if (is_file($temp)) { unlink($temp); } }
        return $target;
    }

    private function generateProcedural(array $row, string $inputPath): void {
        $dir=$this->artifactDir.'/procedural-work'; $this->directory($dir);
        $name=bin2hex(random_bytes(12));
        $path=$dir.'/'.$name.'.glb'; $manifest=$dir.'/'.$name.'.manifest.json';
        $venv=dirname(__DIR__).'/.venv-procedural/bin/python';
        $python=$this->config['procedural_python'] ?? (getenv('GEOTEXTURE_PYTHON') ?: (is_executable($venv) ? $venv : 'python3'));
        try {
            ProceduralRunner::generate($inputPath,$path,$python);
            if ($this->jobRow($row['job_id'])['status']==='cancelled') { return; }
            $this->registerResult([
                'source_key'=>$row['source_key'],'build_id'=>$row['build_id'],
                'source_hash'=>$row['source_hash'],'recipe_key'=>$row['recipe_key'],
                'path'=>'procedural-work/'.$name.'.glb','manifest_path'=>'procedural-work/'.$name.'.manifest.json',
            ]);
        } finally {
            foreach ([$path,$manifest] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }

    public function registerResult(array $input): array {
        foreach (['source_key','build_id','source_hash','recipe_key','path'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') { throw new InvalidArgumentException('成果登錄缺少 '.$field); }
        }
        if (!preg_match('/^[a-f0-9]{64}$/D',$input['source_hash'])) { throw new InvalidArgumentException('source_hash 必須是 SHA-256'); }
        $versions = [$input['source_key'],$input['build_id'],$input['source_hash'],$input['recipe_key']];
        $items = $this->rows('SELECT * FROM job_items WHERE source_key=? AND build_id=? AND source_hash=? AND recipe_key=? ORDER BY rowid LIMIT 1',$versions);
        if (!$items) { throw new AppException('成果版本不符合任何已凍結的任務項目',422); }
        $path = $this->artifactPath($input['path']);
        $manifest=null; $quality=[];
        if (array_key_exists('manifest_path',$input)) {
            if (!is_string($input['manifest_path']) || $input['manifest_path']==='') { throw new InvalidArgumentException('manifest_path 必須為相對成果路徑'); }
            $manifestPath=$this->artifactPath($input['manifest_path']);
            if (filesize($manifestPath)>2097152) { throw new InvalidArgumentException('成果 manifest 超過大小上限'); }
            try { $manifest=json_decode(file_get_contents($manifestPath),true,64,JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new InvalidArgumentException('成果 manifest JSON 無效',0,$e); }
            if (!is_array($manifest) || array_is_list($manifest)) { throw new InvalidArgumentException('成果 manifest 必須為 object'); }
            $quality=$this->registrationQuality($manifest,$input,$path,$this->validateGlb($path,true));
        } else {
            if (Recipes::procedural($input['recipe_key'])) { throw new InvalidArgumentException('示意立面必須附上風格 manifest'); }
            $this->validateGlb($path);
        }
        $feature = json_decode($items[0]['feature_json'],true,512,JSON_THROW_ON_ERROR);
        $bbox = Geometry::bbox($feature['geometry']);
        $model = ['longitude'=>($bbox[0]+$bbox[2])/2,'latitude'=>($bbox[1]+$bbox[3])/2,'altitude'=>0.0,'heading'=>0.0,'scale'=>1.0];
        foreach ($model as $field=>$default) {
            $placement=$manifest ?? $input;
            if ($manifest!==null && !array_key_exists($field,$placement)) { throw new InvalidArgumentException('成果 manifest 缺少定位：'.$field); }
            if (array_key_exists($field,$placement)) {
                if (!is_numeric($placement[$field]) || !is_finite((float)$placement[$field])) { throw new InvalidArgumentException('模型定位參數無效：'.$field); }
                $model[$field] = (float)$placement[$field];
                if ($manifest!==null && array_key_exists($field,$input) && (!is_numeric($input[$field]) || (float)$input[$field]!==$model[$field])) { throw new InvalidArgumentException('CLI 定位與 manifest 不一致：'.$field); }
            }
        }
        $model+=$quality;
        if (Recipes::procedural($input['recipe_key'])) {
            $expected=['longitude'=>($bbox[0]+$bbox[2])/2,'latitude'=>($bbox[1]+$bbox[3])/2,'altitude'=>0,'heading'=>0,'scale'=>1];
            foreach ($expected as $field=>$value) {
                if (abs($model[$field]-$value)>1e-7) { throw new InvalidArgumentException('示意立面定位必須符合原始建物：'.$field); }
            }
        }
        if (abs($model['longitude'])>180 || abs($model['latitude'])>90 || $model['scale']<=0) { throw new InvalidArgumentException('模型位置或比例超出範圍'); }
        $id = bin2hex(random_bytes(16));
        $relative = 'models/'.$id.'.glb';
        $this->directory($this->artifactDir.'/models');
        $target = $this->artifactDir.'/'.$relative;
        // 以不可變的新檔保存，強制重做不覆蓋先前已登錄的成果。
        if (!copy($path,$target)) { throw new RuntimeException('無法保存成果'); }
        try {
            $this->validateGlb($target);
            if ($manifest!==null && !hash_equals($manifest['model_sha256'],hash_file('sha256',$target))) { throw new InvalidArgumentException('模型內容在登錄期間改變'); }
            $this->db->beginTransaction();
            $this->statement('INSERT INTO assets VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,...$versions,$relative,filesize($target),hash_file('sha256',$target),$this->json($model),$this->now()]);
            $itemStatus=($model['texture_status'] ?? null)==='partial' ? 'partial' : 'completed';
            $this->statement("UPDATE job_items SET asset_id=?,status=?,error=NULL WHERE source_key=? AND build_id=? AND source_hash=? AND recipe_key=? AND job_id IN(SELECT id FROM jobs WHERE status<>'cancelled')",[$id,$itemStatus,...$versions]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            unlink($target);
            throw $e;
        }
        $this->jobs();
        return ['asset_id'=>$id,'source_key'=>$versions[0],'build_id'=>$versions[1],'source_hash'=>$versions[2],'recipe_key'=>$versions[3],'model'=>$this->model($this->assetById($id))];
    }

    private function validAsset(string $sourceKey, string $buildId, string $hash, string $recipe): ?array {
        $rows = $this->rows('SELECT * FROM assets WHERE source_key=? AND build_id=? AND source_hash=? AND recipe_key=? ORDER BY created_at DESC,rowid DESC',[$sourceKey,$buildId,$hash,$recipe]);
        foreach ($rows as $row) { if ($this->assetValid($row)) { return $row; } }
        return null;
    }

    private function assetById(string $id): ?array {
        $rows = $this->rows('SELECT * FROM assets WHERE asset_id=?',[$id]);
        return $rows && $this->assetValid($rows[0]) ? $rows[0] : null;
    }

    private function assetValid(array $row): bool {
        try {
            $path = $this->artifactPath($row['relative_path']);
            // 登錄時已完整驗證網格。逐次核對同一份內容的 SHA-256，無須重解每個頂點。
            // 不快取檢查結果，等長覆寫、遺失或損壞仍會在下次讀取時被拒絕。
            clearstatcache(true,$path);
            return filesize($path) === (int)$row['size'] && hash_equals($row['sha256'],hash_file('sha256',$path));
        } catch (Throwable) { return false; }
    }

    private function isPartial(array $asset): bool {
        return (json_decode($asset['model_json'],true,512,JSON_THROW_ON_ERROR)['texture_status'] ?? null)==='partial';
    }

    private function artifactPath(string $relative): string {
        if ($relative === '' || str_contains($relative,"\0") || str_starts_with($relative,'/') || str_starts_with($relative,'\\') || preg_match('/^[A-Za-z]:/',$relative) || in_array('..',preg_split('~[/\\\\]+~',$relative),true)) { throw new InvalidArgumentException('成果路徑必須位於 artifact root 內'); }
        $root = realpath($this->artifactDir);
        $path = realpath($this->artifactDir . '/' . $relative);
        $prefix = rtrim(str_replace('\\','/',$root ?: ''),'/').'/';
        $normalized = str_replace('\\','/',$path ?: '');
        $inside = PHP_OS_FAMILY==='Windows' ? str_starts_with(strtolower($normalized),strtolower($prefix)) : str_starts_with($normalized,$prefix);
        if (!$path || !$inside || !is_file($path)) { throw new AppException('成果檔案不存在或超出 artifact root',404); }
        return $path;
    }

    private function validateGlb(string $path, bool $withTextures=false): array {
        $size = filesize($path);
        if ($size === false || $size < 28) { throw new InvalidArgumentException('不是有效的 GLB 2.0 檔案'); }
        $file = fopen($path,'rb');
        if (!$file) { throw new InvalidArgumentException('無法讀取 GLB'); }
        try {
            $header = fread($file,20);
            $h = unpack('a4magic/Vversion/Vlength/VchunkLength/a4chunkType',$header);
            if ($h['magic']!=='glTF' || $h['version']!==2 || $h['length']!==$size || $h['chunkType']!=='JSON' || $h['chunkLength']<4 || $h['chunkLength']%4!==0 || $h['chunkLength']>$size-20 || $h['chunkLength']>16777216) { throw new InvalidArgumentException('GLB header 或 JSON chunk 無效'); }
            try { $json = json_decode(fread($file,$h['chunkLength']),true,512,JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new InvalidArgumentException('GLB JSON 無效',0,$e); }
            if (!is_array($json) || ($json['asset']['version'] ?? '')!=='2.0') { throw new InvalidArgumentException('GLB 缺少 glTF 2.0 模型'); }
            foreach (['buffers','images'] as $field) {
                if (isset($json[$field]) && (!is_array($json[$field]) || !array_is_list($json[$field]))) { throw new InvalidArgumentException('GLB 資源清單格式無效'); }
                foreach ($json[$field] ?? [] as $resource) {
                    if (!is_array($resource) || (isset($resource['uri']) && (!is_string($resource['uri']) || !str_starts_with($resource['uri'],'data:')))) { throw new InvalidArgumentException('GLB 不可依賴外部資源路徑'); }
                }
            }
            $offset=20+$h['chunkLength']; $binaryLength=null; $binaryOffset=null;
            while ($offset<$size) {
                if ($size-$offset<8) { throw new InvalidArgumentException('GLB chunk 已截斷'); }
                $chunk=unpack('Vlength/a4type',fread($file,8));
                if ($chunk['length']%4!==0 || $chunk['length']>$size-$offset-8) { throw new InvalidArgumentException('GLB chunk 長度無效'); }
                if ($chunk['type']==="BIN\0") {
                    if ($binaryLength!==null || $offset!==20+$h['chunkLength']) { throw new InvalidArgumentException('GLB BIN chunk 重複或順序無效'); }
                    $binaryLength=$chunk['length']; $binaryOffset=$offset+8;
                }
                if ($chunk['type']==='JSON') { throw new InvalidArgumentException('GLB 含重複 JSON chunk'); }
                fseek($file,$chunk['length'],SEEK_CUR);
                $offset+=8+$chunk['length'];
            }
            $buffers=$this->validateGlbGeometry($json,$file,$binaryOffset,$binaryLength);
            return $withTextures ? $this->glbTextureQuality($json,$buffers,$file) : [];
        } finally { fclose($file); }
    }

    /** 只接受可直接讀取的 core 網格；未支援的壓縮／稀疏資料不可當作完成成果。 */
    private function validateGlbGeometry(array $json, $file, ?int $binaryOffset, ?int $binaryLength): array {
        foreach (['scenes','nodes','meshes','accessors','bufferViews','buffers'] as $field) {
            if (empty($json[$field]) || !is_array($json[$field]) || !array_is_list($json[$field])) { throw new InvalidArgumentException('GLB 缺少可繪製的 '.$field); }
        }
        if (!empty($json['extensionsRequired'])) { throw new InvalidArgumentException('目前不支援需要擴充解碼器的 GLB'); }
        $buffers=[];
        foreach ($json['buffers'] as $index=>$buffer) {
            if (!is_array($buffer) || !isset($buffer['byteLength']) || !is_int($buffer['byteLength']) || $buffer['byteLength']<1) { throw new InvalidArgumentException('GLB buffer 長度無效'); }
            $data=null;
            if (isset($buffer['uri'])) {
                if (!preg_match('~^data:application/(?:octet-stream|gltf-buffer)(;base64)?,(.*)$~sD',$buffer['uri'],$parts)) { throw new InvalidArgumentException('GLB buffer data URI 格式不支援'); }
                $data=($parts[1] ?? '')===';base64' ? base64_decode($parts[2],true) : rawurldecode($parts[2]);
                if ($data===false || strlen($data)<$buffer['byteLength']) { throw new InvalidArgumentException('GLB buffer data URI 資料不足'); }
            } elseif ($index!==0 || $binaryLength===null || $buffer['byteLength']>$binaryLength || $binaryLength-$buffer['byteLength']>3) { throw new InvalidArgumentException('GLB buffer 與 BIN chunk 長度不符'); }
            $buffers[]=['length'=>$buffer['byteLength'],'offset'=>$binaryOffset,'data'=>$data];
        }
        $scene=$this->glbIndex($json['scene'] ?? 0,$json['scenes'],'scene');
        $roots=$json['scenes'][$scene]['nodes'] ?? null;
        if (!is_array($roots) || !array_is_list($roots) || !$roots) { throw new InvalidArgumentException('GLB scene 沒有可達網格'); }
        $visited=[]; $meshCount=0; $validatedMeshes=[]; $pending=$roots;
        while ($pending) {
            $nodeIndex=array_pop($pending);
            $nodeIndex=$this->glbIndex($nodeIndex,$json['nodes'],'node');
            if (isset($visited[$nodeIndex])) { throw new InvalidArgumentException('GLB node 階層含循環或重複父節點'); }
            $visited[$nodeIndex]=true; $node=$json['nodes'][$nodeIndex];
            if (!is_array($node)) { throw new InvalidArgumentException('GLB node 格式無效'); }
            if (isset($node['mesh'])) {
                $mesh=$this->glbIndex($node['mesh'],$json['meshes'],'mesh');
                if (!isset($validatedMeshes[$mesh])) {
                    $this->validateGlbPrimitives($json['meshes'][$mesh],$json,$buffers,$file);
                    $validatedMeshes[$mesh]=true;
                }
                $meshCount++;
            }
            $children=$node['children'] ?? [];
            if (!is_array($children) || !array_is_list($children)) { throw new InvalidArgumentException('GLB children 格式無效'); }
            foreach ($children as $child) { $pending[]=$child; }
        }
        if ($meshCount===0) { throw new InvalidArgumentException('GLB scene 沒有可達網格'); }
        return $buffers;
    }

    private function glbIndex(mixed $index, array $items, string $label): int {
        if (!is_int($index) || $index<0 || !array_key_exists($index,$items)) { throw new InvalidArgumentException('GLB '.$label.' 索引無效'); }
        return $index;
    }

    private function glbAccessor(mixed $index, array $json, array $buffers, bool $vertex): array {
        $index=$this->glbIndex($index,$json['accessors'],'accessor');
        $a=$json['accessors'][$index];
        if (!is_array($a) || isset($a['sparse'])) { throw new InvalidArgumentException('GLB accessor 格式無效或使用尚未支援的 sparse'); }
        $viewIndex=$this->glbIndex($a['bufferView'] ?? null,$json['bufferViews'],'bufferView');
        $v=$json['bufferViews'][$viewIndex];
        if (!is_array($v)) { throw new InvalidArgumentException('GLB bufferView 格式無效'); }
        $buffer=$this->glbIndex($v['buffer'] ?? null,$buffers,'buffer');
        $componentBytes=[5120=>1,5121=>1,5122=>2,5123=>2,5125=>4,5126=>4];
        $components=['SCALAR'=>1,'VEC2'=>2,'VEC3'=>3,'VEC4'=>4];
        if (!is_int($a['componentType'] ?? null) || !isset($componentBytes[$a['componentType']]) || !is_string($a['type'] ?? null) || !isset($components[$a['type']])) { throw new InvalidArgumentException('GLB accessor 資料型別無效'); }
        $component=$componentBytes[$a['componentType']]; $element=$component*$components[$a['type']];
        $count=$a['count'] ?? null; $viewLength=$v['byteLength'] ?? null;
        $viewOffset=$v['byteOffset'] ?? 0; $offset=$a['byteOffset'] ?? 0; $stride=$v['byteStride'] ?? $element;
        foreach ([$count,$viewLength,$viewOffset,$offset,$stride] as $value) { if (!is_int($value) || $value<0) { throw new InvalidArgumentException('GLB accessor 位移或長度無效'); } }
        if ($count<1 || $viewLength<1 || $viewOffset>$buffers[$buffer]['length'] || $viewLength>$buffers[$buffer]['length']-$viewOffset || $offset>$viewLength || $element>$viewLength-$offset || $stride<$element || $stride%$component!==0 || $offset%$component!==0 || ($viewOffset+$offset)%$component!==0) { throw new InvalidArgumentException('GLB accessor 超出 buffer／bufferView 範圍'); }
        if (isset($v['byteStride']) && ($stride<4 || $stride>252 || $stride%4!==0 || !$vertex)) { throw new InvalidArgumentException('GLB byteStride 無效'); }
        if ($vertex && ($offset%4!==0 || ($count>1 && $stride%4!==0))) { throw new InvalidArgumentException('GLB 頂點資料未依四位元組對齊'); }
        if ($count-1>intdiv($viewLength-$offset-$element,$stride)) { throw new InvalidArgumentException('GLB accessor count 超出 bufferView'); }
        return ['accessor'=>$a,'buffer'=>$buffer,'offset'=>$viewOffset+$offset,'stride'=>$stride,'element'=>$element,'count'=>$count,'type'=>$a['type'],'componentType'=>$a['componentType']];
    }

    private function validateGlbPrimitives(mixed $mesh, array $json, array $buffers, $file): void {
        $primitives=is_array($mesh) ? ($mesh['primitives'] ?? null) : null;
        if (!is_array($primitives) || !array_is_list($primitives) || !$primitives) { throw new InvalidArgumentException('GLB mesh 沒有 primitives'); }
        foreach ($primitives as $primitive) {
            $attributes=is_array($primitive) ? ($primitive['attributes'] ?? null) : null;
            if (!is_array($attributes) || !array_key_exists('POSITION',$attributes)) { throw new InvalidArgumentException('GLB primitive 缺少 POSITION'); }
            $position=$this->glbAccessor($attributes['POSITION'],$json,$buffers,true);
            if ($position['type']!=='VEC3' || $position['componentType']!==5126 || !empty($position['accessor']['normalized'])) { throw new InvalidArgumentException('GLB POSITION 必須是 FLOAT VEC3'); }
            foreach (['min','max'] as $field) {
                $bounds=$position['accessor'][$field] ?? null;
                if (!is_array($bounds) || !array_is_list($bounds) || count($bounds)!==3) { throw new InvalidArgumentException('GLB POSITION 缺少 min／max'); }
                foreach ($bounds as $number) { if ((!is_float($number) && !is_int($number)) || !is_finite((float)$number)) { throw new InvalidArgumentException('GLB POSITION 範圍無效'); } }
            }
            for ($axis=0;$axis<3;$axis++) { if ($position['accessor']['min'][$axis]>$position['accessor']['max'][$axis]) { throw new InvalidArgumentException('GLB POSITION min／max 順序無效'); } }
            foreach ($attributes as $attribute) {
                if ($this->glbAccessor($attribute,$json,$buffers,true)['count']!==$position['count']) { throw new InvalidArgumentException('GLB primitive 屬性頂點數不一致'); }
            }
            // 檢查實際 bytes，不能只相信 JSON 宣告；分塊讀取避免大型模型耗盡記憶體。
            $this->scanGlbAccessor($position,$buffers,$file,static function(string $bytes) use ($position): void {
                foreach (array_values(unpack('g3',$bytes)) as $axis=>$value) {
                    if (!is_finite($value) || $value<$position['accessor']['min'][$axis]-1e-6 || $value>$position['accessor']['max'][$axis]+1e-6) { throw new InvalidArgumentException('GLB POSITION 含非有限值或超出宣告範圍'); }
                }
            });
            $drawCount=$position['count'];
            if (array_key_exists('indices',$primitive)) {
                $indices=$this->glbAccessor($primitive['indices'],$json,$buffers,false);
                if ($indices['type']!=='SCALAR' || !in_array($indices['componentType'],[5121,5123,5125],true) || !empty($indices['accessor']['normalized'])) { throw new InvalidArgumentException('GLB indices 必須是 unsigned SCALAR'); }
                $format=[5121=>'C',5123=>'v',5125=>'V'][$indices['componentType']];
                $this->scanGlbAccessor($indices,$buffers,$file,static function(string $bytes) use ($format,$position): void {
                    if (unpack($format,$bytes)[1]>=$position['count']) { throw new InvalidArgumentException('GLB indices 指向不存在的頂點'); }
                });
                $drawCount=$indices['count'];
            }
            $mode=$primitive['mode'] ?? 4;
            if (!is_int($mode) || $mode<0 || $mode>6 || ($mode===1 && $drawCount%2!==0) || ($mode===4 && $drawCount%3!==0) || (in_array($mode,[1,2,3],true) && $drawCount<2) || (in_array($mode,[4,5,6],true) && $drawCount<3)) { throw new InvalidArgumentException('GLB primitive 頂點數不符合繪圖模式'); }
        }
    }

    private function scanGlbAccessor(array $accessor, array $buffers, $file, callable $check): void {
        $buffer=$buffers[$accessor['buffer']];
        $batch=max(1,intdiv(262144,$accessor['stride']));
        for ($first=0;$first<$accessor['count'];$first+=$batch) {
            $count=min($batch,$accessor['count']-$first);
            $offset=$accessor['offset']+$first*$accessor['stride'];
            $length=($count-1)*$accessor['stride']+$accessor['element'];
            if ($buffer['data']!==null) { $bytes=substr($buffer['data'],$offset,$length); }
            else { fseek($file,$buffer['offset']+$offset); $bytes=fread($file,$length); }
            if (strlen($bytes)!==$length) { throw new InvalidArgumentException('GLB 頂點資料已截斷'); }
            for ($i=0;$i<$count;$i++) { $check(substr($bytes,$i*$accessor['stride'],$accessor['element'])); }
        }
    }

    private function registrationQuality(array $manifest, array $input, string $path, array $actual): array {
        if (Recipes::procedural($input['recipe_key'])) { return $this->proceduralQuality($manifest,$input,$path,$actual); }
        if (($manifest['appearance_kind'] ?? null)==='procedural') { throw new InvalidArgumentException('示意立面不可登錄為實景成果'); }
        if (($manifest['version'] ?? null)!==1) { throw new InvalidArgumentException('成果 manifest 版本無效'); }
        foreach (['source_key','build_id','source_hash','recipe_key'] as $field) {
            if (($manifest[$field] ?? null)!==$input[$field]) { throw new InvalidArgumentException('成果 manifest 來源版本不符：'.$field); }
        }
        foreach (['imagery_hash','model_sha256'] as $field) {
            if (!is_string($manifest[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$manifest[$field])) { throw new InvalidArgumentException('成果 manifest hash 無效：'.$field); }
        }
        if (!hash_equals($manifest['model_sha256'],hash_file('sha256',$path))) { throw new InvalidArgumentException('成果 manifest 模型 SHA 不符'); }
        if (!is_string($manifest['engine_commit'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D',$manifest['engine_commit'])) { throw new InvalidArgumentException('成果 manifest 引擎版本無效'); }
        foreach (['surfaces_total','surfaces_textured','walls_total','walls_textured'] as $field) {
            if (!is_int($manifest[$field] ?? null) || $manifest[$field]<0 || $manifest[$field]!==$actual[$field]) { throw new InvalidArgumentException('成果 manifest 面數與 GLB 不符：'.$field); }
        }
        if ($actual['surfaces_textured']<1 || $actual['walls_textured']<1) { throw new InvalidArgumentException('成果至少需要一面真正貼圖的外牆'); }
        if (!is_array($manifest['surfaces'] ?? null) || !array_is_list($manifest['surfaces']) || count($manifest['surfaces'])!==$actual['surfaces_total']) { throw new InvalidArgumentException('成果 manifest 缺少逐面品質'); }
        $surfaces=[]; $fullyCovered=true;
        foreach ($manifest['surfaces'] as $surface) {
            $key=$surface['key'] ?? null;
            if (!is_string($key) || isset($surfaces[$key]) || !isset($actual['_surfaces'][$key])) { throw new InvalidArgumentException('成果 manifest surface key 缺失或重複'); }
            $real=$actual['_surfaces'][$key];
            if (($surface['kind'] ?? null)!==$real['kind'] || ($surface['textured'] ?? null)!==$real['textured']) { throw new InvalidArgumentException('成果 manifest 面類型／貼圖狀態與 GLB 不符'); }
            foreach (['area_m2','geometric_coverage','coverage'] as $field) {
                if ((!is_int($surface[$field] ?? null) && !is_float($surface[$field] ?? null)) || !is_finite((float)$surface[$field])) { throw new InvalidArgumentException('成果 manifest surface 數值無效：'.$field); }
            }
            if (abs($surface['area_m2']-$real['area_m2'])>max(0.001,$real['area_m2']*0.001) || abs($surface['geometric_coverage']-$real['geometric_coverage'])>0.001 || $surface['coverage']<0 || $surface['coverage']>min(1,$real['geometric_coverage'])+0.001 || (!$real['textured'] && $surface['coverage']!=0)) { throw new InvalidArgumentException('成果 manifest 面積或覆蓋率與 GLB 不符'); }
            $upstream=$surface['upstream_wall_keys'] ?? [];
            if (!is_array($upstream) || !array_is_list($upstream)) { throw new InvalidArgumentException('成果 manifest 上游牆 key 清單無效'); }
            foreach ($upstream as $upstreamKey) { if (!is_string($upstreamKey) || strlen($upstreamKey)>512) { throw new InvalidArgumentException('成果 manifest 上游牆 key 無效'); } }
            $surfaces[$key]=['key'=>$key,'kind'=>$real['kind'],'textured'=>$real['textured'],'area_m2'=>$real['area_m2'],'geometric_coverage'=>$real['geometric_coverage'],'coverage'=>(float)$surface['coverage'],'upstream_wall_keys'=>$upstream];
            if ($surface['coverage']<0.95) { $fullyCovered=false; }
        }
        if (!is_array($manifest['textures'] ?? null) || !array_is_list($manifest['textures']) || !$manifest['textures']) { throw new InvalidArgumentException('成果 manifest 缺少紋理來源'); }
        $textures=[]; $matchedImages=[];
        foreach ($manifest['textures'] as $texture) {
            if (!is_array($texture) || !is_string($texture['upstream_wall_key'] ?? null) || strlen($texture['upstream_wall_key'])>512) { throw new InvalidArgumentException('成果 manifest 紋理來源 key 無效'); }
            foreach (['source_sha256','embedded_sha256'] as $field) { if (!is_string($texture[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$texture[$field])) { throw new InvalidArgumentException('成果 manifest 紋理 SHA 無效'); } }
            $image=$actual['_images'][$texture['embedded_sha256']] ?? null;
            if (!$image || ($texture['width'] ?? null)!==$image['width'] || ($texture['height'] ?? null)!==$image['height'] || !in_array($texture['tier'] ?? null,['A','B'],true)) { throw new InvalidArgumentException('成果 manifest 紋理與 GLB 影像／品質不符'); }
            foreach (['valid_pixel_share','observed_share'] as $field) { if ((!is_int($texture[$field] ?? null) && !is_float($texture[$field] ?? null)) || !is_finite((float)$texture[$field]) || $texture[$field]<=0 || $texture[$field]>1) { throw new InvalidArgumentException('成果 manifest 紋理有效像素比例無效'); } }
            $matchedImages[$texture['embedded_sha256']]=true;
            $textures[]=array_intersect_key($texture,array_flip(['upstream_wall_key','source_sha256','embedded_sha256','width','height','tier','valid_pixel_share','observed_share']));
        }
        if (count($matchedImages)!==count($actual['_images'])) { throw new InvalidArgumentException('成果 manifest 未列出所有實際紋理'); }
        $expected=$fullyCovered ? 'complete' : 'partial';
        if (($manifest['texture_status'] ?? null)!==$expected) { throw new InvalidArgumentException('成果 manifest 貼皮狀態與實際面數不符'); }
        if ($expected==='complete' && ($manifest['verification_status'] ?? null)==='trial_unverified') { throw new InvalidArgumentException('待核對試轉成果不可標記為完成'); }
        if (($manifest['up_axis'] ?? null)!=='Y' || !in_array($manifest['forward_axis'] ?? null,['Z','-Z'],true)) { throw new InvalidArgumentException('成果 manifest 模型軸向無效'); }
        if (!is_array($manifest['attribution'] ?? null) || !array_is_list($manifest['attribution'])) { throw new InvalidArgumentException('成果 attribution 必須為清單'); }
        $attribution=[];
        foreach ($manifest['attribution'] as $credit) {
            if (!is_array($credit) || !is_string($credit['image_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$credit['image_id'])) { throw new InvalidArgumentException('成果 attribution 缺少 image_id'); }
            $url=$credit['source_url'] ?? null;
            if (!is_string($url) || filter_var($url,FILTER_VALIDATE_URL)===false || parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['mapillary.com','www.mapillary.com'],true) || preg_match('/(?:access_token|token|secret)=/i',$url)) { throw new InvalidArgumentException('成果 attribution 來源連結無效'); }
            foreach (['license','changes'] as $field) { if (!is_string($credit[$field] ?? null) || strlen($credit[$field])>4000) { throw new InvalidArgumentException('成果 attribution 欄位無效：'.$field); } }
            $author=null;
            if (isset($credit['author'])) {
                if (!is_array($credit['author'])) { throw new InvalidArgumentException('成果 attribution 作者格式無效'); }
                $author=[];
                foreach (['id','username'] as $field) {
                    if (isset($credit['author'][$field]) && !is_string($credit['author'][$field])) { throw new InvalidArgumentException('成果 attribution 作者欄位無效'); }
                    $author[$field]=$credit['author'][$field] ?? '';
                }
            }
            // 僅公開約定欄位，不將 CLI 本機路徑或額外機密欄位帶入 API／ZIP。
            $attribution[]=['image_id'=>$credit['image_id'],'author'=>$author,'source_url'=>$url,'license'=>$credit['license'],'changes'=>$credit['changes']];
        }
        $quality=['texture_status'=>$expected,'engine_commit'=>$manifest['engine_commit'],'imagery_hash'=>$manifest['imagery_hash'],'up_axis'=>'Y','forward_axis'=>$manifest['forward_axis'],'attribution'=>$attribution];
        foreach (['surfaces_total','surfaces_textured','walls_total','walls_textured'] as $field) { $quality[$field]=$actual[$field]; }
        $quality['surfaces']=array_values($surfaces); $quality['textures']=$textures;
        if (array_key_exists('verification_status',$manifest)) {
            if ($manifest['verification_status']!=='trial_unverified') { throw new InvalidArgumentException('目前貼皮試轉成果必須保留待核對狀態'); }
            $quality['verification_status']='trial_unverified';
        }
        if (array_key_exists('wall_quality',$manifest)) {
            if (!is_array($manifest['wall_quality']) || !array_is_list($manifest['wall_quality']) || count($manifest['wall_quality'])>1000) { throw new InvalidArgumentException('逐牆品質摘要格式無效'); }
            $quality['wall_quality']=[];
            foreach ($manifest['wall_quality'] as $wall) {
                if (!is_array($wall)) { throw new InvalidArgumentException('逐牆品質摘要格式無效'); }
                $summary=$this->qualityScalars($wall,['key','status','tier','height_source','fuse_mode','texture_source'],['confidence','unknown_share','observed_share','engine_observed_share','hole_fraction','height_m','upstream_height_m','height_osm_m','height_used_m','piece','n_pieces','s_offset']);
                $summary['flags']=$this->qualityFlags($wall['flags'] ?? []);
                if (isset($wall['plane'])) {
                    if (!is_array($wall['plane'])) { throw new InvalidArgumentException('牆平面品質摘要格式無效'); }
                    $summary['plane']=$this->qualityScalars($wall['plane'],['source','pano'],['offset_vs_osm_m','angle_vs_osm_deg','pts_per_m','rms_m','n_inliers']);
                }
                $quality['wall_quality'][]=$summary;
            }
        }
        if (array_key_exists('engine_quality',$manifest)) {
            $engine=$manifest['engine_quality'];
            if (!is_array($engine)) { throw new InvalidArgumentException('引擎品質摘要格式無效'); }
            $quality['engine_quality']=[];
            if (isset($engine['registration'])) {
                if (!is_array($engine['registration'])) { throw new InvalidArgumentException('定位品質摘要格式無效'); }
                $registration=$this->qualityScalars($engine['registration'],[],['panos','with_cluster','local','global','none','no_cluster','acceptance_rate_local','acceptance_rate_any','median_shift_m','median_inliers_after','median_inliers_before','theta_used']);
                if (isset($engine['registration']['local_rejection_reasons'])) { $registration['local_rejection_reasons']=$this->qualityCounts($engine['registration']['local_rejection_reasons']); }
                $quality['engine_quality']['registration']=$registration;
            }
            foreach (['ground_sources','plane_sources'] as $field) { if (isset($engine[$field])) { $quality['engine_quality'][$field]=$this->qualityCounts($engine[$field]); } }
            $quality['engine_quality']['warnings']=$this->qualityFlags($engine['warnings'] ?? []);
        }
        return $quality;
    }

    private function proceduralQuality(array $manifest, array $input, string $path, array $actual): array {
        if (($manifest['version'] ?? null)!==1 || ($manifest['appearance_kind'] ?? null)!=='procedural'
            || ($manifest['verification_status'] ?? null)!=='synthetic'
            || ($manifest['style_key'] ?? null)!==$input['recipe_key']
            || ($manifest['texture_status'] ?? null)!=='complete') {
            throw new InvalidArgumentException('示意立面 manifest 類型或風格無效');
        }
        foreach (['source_key','build_id','source_hash','recipe_key'] as $key) {
            if (($manifest[$key] ?? null)!==$input[$key]) { throw new InvalidArgumentException('示意立面來源版本不符'); }
        }
        if (!is_string($manifest['model_sha256'] ?? null) || !hash_equals(hash_file('sha256',$path),$manifest['model_sha256'])) {
            throw new InvalidArgumentException('示意立面模型 SHA 不符');
        }
        foreach (['surfaces_total','surfaces_textured','walls_total','walls_textured'] as $key) {
            if (($manifest[$key] ?? null)!==$actual[$key]) { throw new InvalidArgumentException('示意立面面數不符'); }
        }
        if ($actual['walls_total']<3 || $actual['surfaces_total']<=$actual['walls_total']
            || $actual['surfaces_total']!==$actual['surfaces_textured']) {
            throw new InvalidArgumentException('示意立面必須完整包含外牆與屋頂貼圖');
        }
        if (!is_array($manifest['surfaces'] ?? null) || !array_is_list($manifest['surfaces']) || count($manifest['surfaces'])!==$actual['surfaces_total']) {
            throw new InvalidArgumentException('示意立面缺少逐面資訊');
        }
        $seen=[]; $surfaces=[];
        foreach ($manifest['surfaces'] as $surface) {
            $key=$surface['key'] ?? null;
            if (!is_string($key) || isset($seen[$key]) || !isset($actual['_surfaces'][$key])) { throw new InvalidArgumentException('示意立面 surface key 無效'); }
            $real=$actual['_surfaces'][$key]; $seen[$key]=true;
            if (($surface['kind'] ?? null)!==$real['kind'] || ($surface['textured'] ?? null)!==true
                || !is_numeric($surface['area_m2'] ?? null) || !is_finite((float)$surface['area_m2'])
                || abs((float)$surface['area_m2']-$real['area_m2'])>max(0.001,$real['area_m2']*0.001)
                || ($surface['coverage'] ?? null)!=1 || ($surface['geometric_coverage'] ?? null)!=1
                || abs($real['geometric_coverage']-1)>0.001) { throw new InvalidArgumentException('示意立面面積或覆蓋率不符'); }
            $surfaces[]=['key'=>$key,'kind'=>$real['kind'],'textured'=>true,'area_m2'=>$real['area_m2'],'geometric_coverage'=>1.0,'coverage'=>1.0];
        }
        if (!is_array($manifest['textures'] ?? null) || !array_is_list($manifest['textures']) || !$manifest['textures']) { throw new InvalidArgumentException('示意立面缺少材質資訊'); }
        $images=[];
        foreach ($manifest['textures'] as $texture) {
            $hash=$texture['embedded_sha256'] ?? null;
            $image=is_string($hash) ? ($actual['_images'][$hash] ?? null) : null;
            if (!$image || ($texture['width'] ?? null)!==$image['width'] || ($texture['height'] ?? null)!==$image['height']) { throw new InvalidArgumentException('示意立面圖片資訊不符'); }
            $images[$hash]=['embedded_sha256'=>$hash,'width'=>$image['width'],'height'=>$image['height']];
        }
        if (count($images)!==count($actual['_images']) || ($manifest['up_axis'] ?? null)!=='Y' || ($manifest['forward_axis'] ?? null)!=='-Z') {
            throw new InvalidArgumentException('示意立面材質或軸向資訊不符');
        }
        return [
            'appearance_kind'=>'procedural','verification_status'=>'synthetic','style_key'=>$input['recipe_key'],
            'style_label'=>Recipes::all()[$input['recipe_key']]['label'],'texture_status'=>'complete',
            'up_axis'=>'Y','forward_axis'=>'-Z','attribution'=>[],
            'surfaces_total'=>$actual['surfaces_total'],'surfaces_textured'=>$actual['surfaces_textured'],
            'walls_total'=>$actual['walls_total'],'walls_textured'=>$actual['walls_textured'],
            'surfaces'=>$surfaces,'textures'=>array_values($images),
        ];
    }

    private function qualityScalars(array $input, array $strings, array $numbers): array {
        $summary=[];
        foreach ($strings as $field) {
            if (!array_key_exists($field,$input)) { continue; }
            $value=$input[$field];
            if ($value!==null && (!is_string($value) || strlen($value)>512 || str_contains($value,'://') || preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~',$value))) { throw new InvalidArgumentException('品質摘要文字欄位無效：'.$field); }
            $summary[$field]=$value;
        }
        foreach ($numbers as $field) {
            if (!array_key_exists($field,$input)) { continue; }
            $value=$input[$field];
            if ($value!==null && ((!is_int($value) && !is_float($value)) || !is_finite((float)$value))) { throw new InvalidArgumentException('品質摘要數值欄位無效：'.$field); }
            $summary[$field]=$value;
        }
        return $summary;
    }

    private function qualityFlags(mixed $flags): array {
        if (!is_array($flags) || !array_is_list($flags) || count($flags)>100) { throw new InvalidArgumentException('品質警示清單無效'); }
        foreach ($flags as $flag) { if (!is_string($flag) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$flag)) { throw new InvalidArgumentException('品質警示標記無效'); } }
        return $flags;
    }

    private function qualityCounts(mixed $counts): array {
        if (!is_array($counts) || count($counts)>100) { throw new InvalidArgumentException('品質統計格式無效'); }
        foreach ($counts as $key=>$count) { if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$key) || (!is_int($count) && !is_float($count)) || !is_finite((float)$count) || $count<0) { throw new InvalidArgumentException('品質統計欄位無效'); } }
        return $counts;
    }

    private function glbTextureQuality(array $json, array $buffers, $file): array {
        $quality=['surfaces_total'=>0,'surfaces_textured'=>0,'walls_total'=>0,'walls_textured'=>0];
        $scene=$json['scene'] ?? 0; $pending=$json['scenes'][$scene]['nodes']; $meshes=[];
        while ($pending) {
            $node=$json['nodes'][array_pop($pending)];
            if (isset($node['mesh'])) { $meshes[$node['mesh']]=true; }
            foreach ($node['children'] ?? [] as $child) { $pending[]=$child; }
        }
        $surfaces=[]; $decodedImages=[];
        foreach (array_keys($meshes) as $meshIndex) {
            foreach ($json['meshes'][$meshIndex]['primitives'] as $primitive) {
                $extras=$primitive['extras'] ?? null;
                if (!is_array($extras) || !is_bool($extras['textured'] ?? null)) { throw new InvalidArgumentException('貼皮 GLB primitive 缺少明確 textured 標記'); }
                $kind=$extras['kind'] ?? 'wall'; $required=$extras['required_texture'] ?? true;
                if (!is_bool($required) || (!$required && $kind!=='base')) { throw new InvalidArgumentException('只有封閉底面可排除於貼皮面數'); }
                $wall=!in_array($kind,['roof','base'],true);
                if ($wall) {
                    $key=$extras['wall_key'] ?? null;
                    if (!is_string($key) || $key==='' || strlen($key)>512) { throw new InvalidArgumentException('貼皮 GLB 牆面 key 缺失'); }
                }
                $textureInfo=null;
                if (array_key_exists('material',$primitive)) {
                    if (!is_array($json['materials'] ?? null)) { throw new InvalidArgumentException('GLB 缺少 materials'); }
                    $material=$json['materials'][$this->glbIndex($primitive['material'],$json['materials'],'material')];
                    $textureInfo=$material['pbrMetallicRoughness']['baseColorTexture'] ?? null;
                }
                $textured=$textureInfo!==null;
                if ($textured) {
                    if (!is_array($textureInfo) || !is_array($json['textures'] ?? null) || !is_array($json['images'] ?? null)) { throw new InvalidArgumentException('GLB 貼圖材質鏈無效'); }
                    $texture=$json['textures'][$this->glbIndex($textureInfo['index'] ?? null,$json['textures'],'texture')];
                    $imageIndex=$this->glbIndex($texture['source'] ?? null,$json['images'],'image');
                    if (!isset($decodedImages[$imageIndex])) { $decodedImages[$imageIndex]=$this->validateGlbImage($json['images'][$imageIndex],$json,$buffers,$file); }
                    $uvSet=$textureInfo['texCoord'] ?? 0;
                    if (!is_int($uvSet) || $uvSet<0 || $uvSet>7) { throw new InvalidArgumentException('GLB 貼圖 texCoord 無效'); }
                    $uv=$this->glbAccessor($primitive['attributes']['TEXCOORD_'.$uvSet] ?? null,$json,$buffers,true);
                    $position=$this->glbAccessor($primitive['attributes']['POSITION'],$json,$buffers,true);
                    $normalized=$uv['accessor']['normalized'] ?? false;
                    if ($uv['type']!=='VEC2' || $uv['count']!==$position['count'] || !is_bool($normalized) || !in_array($uv['componentType'],[5126,5121,5123],true) || ($uv['componentType']===5126 ? $normalized : !$normalized)) { throw new InvalidArgumentException('GLB 貼圖 UV 型別或頂點數無效'); }
                    if ($uv['componentType']===5126) {
                        $this->scanGlbAccessor($uv,$buffers,$file,static function(string $bytes): void {
                            foreach (unpack('g2',$bytes) as $value) { if (!is_finite($value)) { throw new InvalidArgumentException('GLB UV 含非有限值'); } }
                        });
                    }
                }
                if ($extras['textured']!==$textured) { throw new InvalidArgumentException('GLB textured 標記與真實材質不符'); }
                if ($required) {
                    $key=$wall ? $extras['wall_key'] : ($extras['surface_key'] ?? null);
                    if (!is_string($key) || $key==='' || strlen($key)>512) { throw new InvalidArgumentException('GLB 必要面缺少 surface key'); }
                    $surfaceKind=$wall ? 'wall' : $kind;
                    if (isset($surfaces[$key]) && $surfaces[$key]['kind']!==$surfaceKind) { throw new InvalidArgumentException('GLB surface key 對應多種面類型'); }
                    $surfaces[$key]??=['kind'=>$surfaceKind,'textured'=>false,'area_m2'=>0.0,'textured_area'=>0.0];
                    $area=$this->glbPrimitiveArea($primitive,$json,$buffers,$file);
                    if ($textured && $area<=0) { throw new InvalidArgumentException('貼圖 primitive 沒有可繪製面積'); }
                    $surfaces[$key]['area_m2']+=$area;
                    if ($textured) { $surfaces[$key]['textured']=true; $surfaces[$key]['textured_area']+=$area; }
                }
            }
        }
        foreach ($surfaces as &$surface) {
            if ($surface['area_m2']<=0) { throw new InvalidArgumentException('GLB 必要面面積為零'); }
            $surface['geometric_coverage']=$surface['textured_area']/$surface['area_m2']; unset($surface['textured_area']);
            $quality['surfaces_total']++; if ($surface['textured']) { $quality['surfaces_textured']++; }
            if ($surface['kind']==='wall') { $quality['walls_total']++; if ($surface['textured']) { $quality['walls_textured']++; } }
        }
        unset($surface);
        $quality['_surfaces']=$surfaces; $quality['_images']=[];
        foreach ($decodedImages as $image) { $quality['_images'][$image['sha256']]=$image; }
        return $quality;
    }

    private function glbPrimitiveArea(array $primitive, array $json, array $buffers, $file): float {
        if (($primitive['mode'] ?? 4)!==4) { throw new InvalidArgumentException('貼皮成果目前僅支援 TRIANGLES'); }
        $position=$this->glbAccessor($primitive['attributes']['POSITION'],$json,$buffers,true);
        $triangle=[]; $area=0.0;
        $point=static function(array $p) use (&$triangle,&$area): void {
            $triangle[]=$p;
            if (count($triangle)===3) {
                [$a,$b,$c]=$triangle; $u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]]; $v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
                $cross=[$u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]];
                $area+=sqrt($cross[0]**2+$cross[1]**2+$cross[2]**2)/2; $triangle=[];
            }
        };
        if (!array_key_exists('indices',$primitive)) { $this->scanGlbAccessor($position,$buffers,$file,static function(string $bytes) use ($point): void { $point(array_values(unpack('g3',$bytes))); }); }
        else {
            $indices=$this->glbAccessor($primitive['indices'],$json,$buffers,false); $format=[5121=>'C',5123=>'v',5125=>'V'][$indices['componentType']]; $buffer=$buffers[$position['buffer']];
            $this->scanGlbAccessor($indices,$buffers,$file,static function(string $bytes) use ($format,$position,$buffer,$file,$point): void {
                $offset=$position['offset']+unpack($format,$bytes)[1]*$position['stride'];
                if ($buffer['data']!==null) { $value=substr($buffer['data'],$offset,12); }
                else { fseek($file,$buffer['offset']+$offset); $value=fread($file,12); }
                $point(array_values(unpack('g3',$value)));
            });
        }
        if (!is_finite($area)) { throw new InvalidArgumentException('GLB 面積超出有效範圍'); }
        return $area;
    }

    private function validateGlbImage(mixed $image, array $json, array $buffers, $file): array {
        if (!is_array($image)) { throw new InvalidArgumentException('GLB image 格式無效'); }
        $mime=$image['mimeType'] ?? null;
        if (isset($image['uri'])) {
            if (!is_string($image['uri']) || !preg_match('~^data:(image/(?:png|jpeg));base64,(.*)$~sD',$image['uri'],$parts)) { throw new InvalidArgumentException('GLB 圖片必須內嵌 PNG／JPEG'); }
            $bytes=base64_decode($parts[2],true); $mime=$mime ?? $parts[1];
            if ($bytes===false || $mime!==$parts[1]) { throw new InvalidArgumentException('GLB 圖片 data URI 無效'); }
        } else {
            $view=$json['bufferViews'][$this->glbIndex($image['bufferView'] ?? null,$json['bufferViews'],'image bufferView')];
            if (!is_array($view)) { throw new InvalidArgumentException('GLB image bufferView 無效'); }
            $buffer=$buffers[$this->glbIndex($view['buffer'] ?? null,$buffers,'image buffer')];
            $offset=$view['byteOffset'] ?? 0; $length=$view['byteLength'] ?? null;
            if (!is_int($offset) || !is_int($length) || $offset<0 || $length<1 || $offset>$buffer['length'] || $length>$buffer['length']-$offset || $length>67108864) { throw new InvalidArgumentException('GLB 圖片資料超出 buffer 範圍'); }
            if ($buffer['data']!==null) { $bytes=substr($buffer['data'],$offset,$length); }
            else { fseek($file,$buffer['offset']+$offset); $bytes=fread($file,$length); }
            if (strlen($bytes)!==$length) { throw new InvalidArgumentException('GLB 圖片資料已截斷'); }
        }
        if (!in_array($mime,['image/png','image/jpeg'],true)) { throw new InvalidArgumentException('GLB 僅接受 PNG／JPEG 貼圖'); }
        $info=@getimagesizefromstring($bytes);
        if (!$info || $info['mime']!==$mime || $info[0]<1 || $info[1]<1 || $info[0]>32768 || $info[1]>32768 || $info[0]*$info[1]>33554432) { throw new InvalidArgumentException('GLB 圖片格式或尺寸無效'); }
        $decoded=@imagecreatefromstring($bytes);
        if ($decoded===false) { throw new InvalidArgumentException('GLB 內嵌圖片無法解碼'); }
        imagedestroy($decoded);
        return ['sha256'=>hash('sha256',$bytes),'width'=>$info[0],'height'=>$info[1]];
    }

    private function model(array $asset, bool $preview = false): array {
        $model=['url'=>'api.php?action=model&asset_id='.rawurlencode($asset['asset_id'])] + json_decode($asset['model_json'],true,512,JSON_THROW_ON_ERROR) + ['asset_id'=>$asset['asset_id']];
        if ($preview) {
            // 地圖只需定位與品質摘要；逐面紀錄仍保留於詳情、獨立預覽及匯出。
            $warnings=$model['engine_quality']['warnings'] ?? [];
            foreach ($model['wall_quality'] ?? [] as $wall) {
                if (($wall['status'] ?? '')!=='missing') { $warnings=array_merge($warnings,$wall['flags'] ?? []); }
            }
            if ($warnings) { $model['engine_quality']['warnings']=array_values(array_unique($warnings)); }
            unset($model['surfaces'],$model['textures'],$model['wall_quality']);
        }
        return $model;
    }

    public function modelFile(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new AppException('找不到成果',404); }
        $asset = $this->assetById($id);
        if (!$asset) { throw new AppException('成果不存在、已損壞或版本無效',404); }
        return ['path'=>$this->artifactPath($asset['relative_path']),'name'=>$id.'.glb','sha256'=>$asset['sha256']];
    }

    private function decorate(array $collection, bool $preview = false): array {
        foreach ($collection['features'] as &$feature) {
            $p = &$feature['properties'];
            $asset = $this->validAsset($p['source_key'],(string)$p['build_id'],$p['source_hash'],$this->recipeKey);
            $p['status'] = 'unprocessed'; $p['model'] = null;
            if ($asset) { $p['status']=$this->isPartial($asset) ? 'queued' : 'completed'; $p['model']=$this->model($asset,$preview); }
            else {
                $rows = $this->rows("SELECT i.status FROM job_items i JOIN jobs j ON j.id=i.job_id WHERE i.source_key=? AND i.build_id=? AND i.source_hash=? AND i.recipe_key=? AND j.status<>'cancelled'",[$p['source_key'],(string)$p['build_id'],$p['source_hash'],$this->recipeKey]);
                foreach ($rows as $row) {
                    if (in_array($row['status'],['queued','prepared','completed'],true)) { $p['status']='queued'; break; }
                    if ($row['status']==='failed') { $p['status']='failed'; }
                }
            }
            unset($p);
        }
        unset($feature);
        return $collection;
    }

    public function export(array $polygon): string {
        $polygon = Geometry::validatePolygon($polygon);
        $collection = $this->decorate($this->completeSelection($polygon));
        $dir = $this->stateDir.'/exports'; $this->directory($dir);
        $path = tempnam($dir,'geotexture-');
        if (!$path) { throw new RuntimeException('無法建立匯出暫存檔'); }
        $zip = new ZipArchive();
        try {
            if ($zip->open($path,ZipArchive::OVERWRITE)!==true) { throw new RuntimeException('無法建立 ZIP'); }
            $manifest = ['version'=>1,'created_at'=>$this->now(),'recipe_key'=>$this->recipeKey,'total'=>count($collection['features']),'completed'=>0,'partial'=>0,'missing'=>0,'items'=>[]];
            foreach ($collection['features'] as $feature) {
                $p = $feature['properties'];
                $asset = $this->validAsset($p['source_key'],(string)$p['build_id'],$p['source_hash'],$this->recipeKey);
                $item = ['source_key'=>$p['source_key'],'build_id'=>(string)$p['build_id'],'source_hash'=>$p['source_hash'],'recipe_key'=>$this->recipeKey,'status'=>$asset ? ($this->isPartial($asset) ? 'partial' : 'completed') : 'missing','model'=>null];
                if ($asset) {
                    $archiveName = 'models/'.$asset['asset_id'].'.glb';
                    if (!$zip->addFile($this->artifactPath($asset['relative_path']),$archiveName)) { throw new RuntimeException('無法加入模型檔'); }
                    $item['model'] = ['path'=>$archiveName,'sha256'=>$asset['sha256']] + json_decode($asset['model_json'],true,512,JSON_THROW_ON_ERROR) + ['asset_id'=>$asset['asset_id']];
                    $manifest[$this->isPartial($asset) ? 'partial' : 'completed']++;
                } else { $manifest['missing']++; $item['reason']='尚未登錄相同來源與配方版本的有效 GLB'; }
                $manifest['items'][] = $item;
            }
            $zip->addFromString('selection.geojson',$this->json(['type'=>'Feature','properties'=>['area_m2'=>Geometry::area($polygon)],'geometry'=>$polygon]));
            $zip->addFromString('buildings.geojson',$this->json($collection));
            $zip->addFromString('manifest.json',$this->json($manifest));
            if (!$zip->close()) { throw new RuntimeException('ZIP 寫入失敗'); }
            return $path;
        } catch (Throwable $e) { if (is_file($path)) { @unlink($path); } throw $e; }
    }

    private function rows(string $sql, array $values=[]): array { return $this->statement($sql,$values)->fetchAll(); }
    private function statement(string $sql, array $values=[]): \PDOStatement {
        $s=$this->db->prepare($sql);
        foreach (array_values($values) as $index=>$value) { $s->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR)); }
        $s->execute(); return $s;
    }
    private function directory(string $path): void {
        if (!is_dir($path) && !@mkdir($path,0775,true) && !is_dir($path)) { throw new RuntimeException('無法建立目錄：'.$path); }
    }
    private function json(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
