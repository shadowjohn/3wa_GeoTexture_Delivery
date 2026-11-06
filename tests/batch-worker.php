<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use GeoTexture\App;
use GeoTexture\ProceduralRunner;
function ensure(bool $ok,string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function rejected(callable $fn): void { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
$tmp=sys_get_temp_dir().'/geotexture-batch-'.bin2hex(random_bytes(6));
mkdir($tmp,0755);
try {
    $db=new PDO('sqlite:'.$tmp.'/source.sqlite');
    $db->exec('CREATE TABLE [8418](ogc_fid INTEGER PRIMARY KEY,build_id TEXT,buildname TEXT,build_h REAL,build_no INTEGER,GEOMETRY_WKT TEXT); CREATE VIRTUAL TABLE idx_8418_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax)');
    for ($i=1;$i<=6;$i++) {
        $height=$i===3 ? 'NULL' : '12'; $x=121.4+$i*0.0001; $right=$x+0.00005;
        $db->exec("INSERT INTO [8418] VALUES($i,'B$i','測試建物',$height,4,'POLYGON (($x 25.1701,$right 25.1701,$right 25.1702,$x 25.1702,$x 25.1701))'); INSERT INTO idx_8418_GEOMETRY VALUES($i,$x,$right,25.1701,25.1702)");
    }
    $root=dirname(__DIR__);
    // Keep the venv executable path: resolving its symlink loses the installed packages.
    $python=is_executable($root.'/.venv-procedural/bin/python') ? $root.'/.venv-procedural/bin/python' : trim(shell_exec('command -v python3'));
    $launcher=$tmp.'/python';
    file_put_contents($launcher,'#!'.$python."\nimport json, os, sqlite3, sys, time\nfrom pathlib import Path\nsys.path.insert(0, ".json_encode($root,JSON_UNESCAPED_SLASHES).")\nfrom pipeline import procedural\nbase = Path(".json_encode($tmp,JSON_UNESCAPED_SLASHES).")\nwith (base / 'starts').open('a') as f: f.write(str(os.getpid()) + '\\n')\nmode = (base / 'mode').read_text() if (base / 'mode').exists() else ''\nif mode == 'hang': time.sleep(60)\nif mode == 'protocol':\n    sys.stdin.readline()\n    print('not json', flush=True)\n    time.sleep(60)\nif mode == 'crash': sys.exit(2)\nif hasattr(procedural, 'generate_request'):\n    original = procedural.generate_request\n    def observed(request, output):\n        with sqlite3.connect(base / 'state/app.sqlite') as db:\n            count = db.execute(\"SELECT COUNT(*) FROM job_items WHERE status='completed' AND job_id=(SELECT id FROM jobs ORDER BY rowid LIMIT 1)\").fetchone()[0]\n            with (base / 'progress').open('a') as f: f.write(str(count) + '\\n')\n            if mode == 'cancel': db.execute(\"UPDATE jobs SET status='cancelled'\")\n        return original(request, output)\n    procedural.generate_request = observed\nsys.argv = [sys.argv[0]] + sys.argv[sys.argv.index('pipeline.procedural') + 1:]\nprocedural.main()\n");
    chmod($launcher,0755);
    $config=['source_db'=>$tmp.'/source.sqlite','state_dir'=>$tmp.'/state','procedural_python'=>$launcher];
    $app=new App($config+['recipe_key'=>'procedural-tw-v1']);
    $polygon=['type'=>'Polygon','coordinates'=>[[[121.4,25.17],[121.401,25.17],[121.401,25.171],[121.4,25.171],[121.4,25.17]]]];
    $job=$app->createJob('batch',$polygon);
    $overlap=$app->createJob('overlap',$polygon);
    $cancelled=$app->createJob('cancelled force',$polygon,true);
    $app->cancelJob($cancelled['id']);
    $stats=$app->runBatch(6,$job['id']);
    ensure($stats['processed']===6 && $stats['completed']===5 && $stats['failed']===1,'Isolate invalid height within the batch');
    ensure(count(file($tmp.'/starts'))===1,'One Python process per batch');
    ensure(!is_dir($tmp.'/state/inputs'),'Procedural work does not write input files');
    ensure(array_map('intval',file($tmp.'/progress'))===[0,1,2,2,3,4],'Commit incremental progress before the next request');
    $progress=array_column($app->progress()['jobs'],null,'id');
    ensure($progress[$job['id']]['status']==='failed' && $progress[$job['id']]['completed']===5,'Progress endpoint sees current summary without a full read');
    ensure($progress[$overlap['id']]['status']==='preparing' && $progress[$overlap['id']]['completed']===5,'Refresh overlapping affected jobs');
    ensure($progress[$cancelled['id']]['status']==='cancelled' && $progress[$cancelled['id']]['completed']===0,'Registration never revives cancelled jobs');
    foreach (file($tmp.'/starts') as $pid) { ensure(!posix_kill((int)$pid,0),'Batch closes and reaps Python'); }
    $duplicates=new App(array_replace($config,['state_dir'=>$tmp.'/overlap','recipe_key'=>'procedural-tw-v1']));
    $duplicates->createJob('first',$polygon); $duplicates->createJob('second',$polygon);
    $duplicateStats=$duplicates->runBatch(12);
    ensure($duplicateStats['completed']===5 && $duplicateStats['failed']===2,'Skip selected rows already satisfied by another job in the same batch');
    ensure(count(glob($tmp.'/overlap/artifacts/models/*.glb'))===5,'Register one asset per valid building across overlapping jobs');
    $starts=count(file($tmp.'/starts'));
    $photo=new App($config); $legacy=$photo->createJob('photo',$polygon);
    ensure($photo->runBatch(1,$legacy['id'])['prepared']===1,'Keep photographic input preparation');
    ensure(count(glob($tmp.'/state/inputs/*.json'))===1 && count(file($tmp.'/starts'))===$starts,'Photos retain input JSON without Python');
    $input=json_decode(file_get_contents(glob($tmp.'/state/inputs/*.json')[0]),true);
    $input['recipe_key']='procedural-tw-v1';
    $output=$tmp.'/direct.glb';
    $runner=new ProceduralRunner($launcher);
    foreach (['protocol','crash','hang'] as $mode) {
        file_put_contents($tmp.'/mode',$mode);
        $started=microtime(true);
        rejected(fn()=>$runner->generateRequest($input,$output));
        ensure(microtime(true)-$started<24,'Child errors and timeout are bounded');
        $pid=(int)array_slice(file($tmp.'/starts'),-1)[0];
        ensure(!posix_kill($pid,0),'Failed process is reaped');
        unlink($tmp.'/mode');
        $runner->generateRequest($input,$output);
        ensure(is_file($output),'Next request restarts after process failure');
        $runner->close();
    }
    $forced=$app->createJob('cancel during generation',$polygon,true);
    $state=new PDO('sqlite:'.$tmp.'/state/app.sqlite');
    $before=(int)$state->query('SELECT COUNT(*) FROM assets')->fetchColumn();
    file_put_contents($tmp.'/mode','cancel');
    $stats=$app->runBatch(6,$forced['id']);
    ensure($stats['processed']===0,'Do not mark work complete after concurrent cancellation');
    ensure((int)$state->query('SELECT COUNT(*) FROM assets')->fetchColumn()===$before,'Do not register output after cancellation');
    ensure($app->progress()['jobs'][0]['status']==='cancelled','Keep concurrent cancellation');
    ensure(glob($tmp.'/state/artifacts/procedural-work/*')===[],'Clean staging files after cancellation');
    echo "PASS batch worker: process reuse / progress / overlap / isolation / photo / restart / timeout / cancellation\n";
} finally {
    if (isset($runner)) { $runner->close(); }
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname()); }
    rmdir($tmp);
}
