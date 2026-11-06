<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use GeoTexture\BuildingSource;
function verify(bool $ok,string $why): void {if(!$ok){throw new RuntimeException($why);}}
$tmp=sys_get_temp_dir().'/geotexture-source-'.bin2hex(random_bytes(5));mkdir($tmp,0755);
try {
 $path=$tmp.'/source.sqlite';$db=new PDO('sqlite:'.$path);
 foreach(['8','8418'] as $table) {
  $db->exec("CREATE TABLE [$table](ogc_fid INTEGER PRIMARY KEY,build_id TEXT,buildname TEXT,build_h REAL,build_no INTEGER,GEOMETRY_WKT TEXT); CREATE VIRTUAL TABLE idx_{$table}_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax)");
  $db->exec("INSERT INTO [$table] VALUES(1,'B1','station',15,1,'POLYGON ((120.6 24.1,120.601 24.1,120.601 24.101,120.6 24.101,120.6 24.1))'); INSERT INTO idx_{$table}_GEOMETRY VALUES(1,120.6,120.601,24.1,24.101)");
 }
 $geometry=['type'=>'Polygon','coordinates'=>[[[120.6004,24.1],[120.6006,24.1],[120.6006,24.101],[120.6004,24.101],[120.6004,24.1]]]];
 $data=['source'=>'test OSM attribution','features'=>[['properties'=>['build_id'=>'B1','base_m'=>6,'name'=>'station','width_scale'=>1],'geometry'=>$geometry]]];
 $open=$tmp.'/openings.json';file_put_contents($open,json_encode($data));$before=hash_file('sha256',$path);
 $tc=new BuildingSource($path,'taichung-8',$open);$nt=new BuildingSource($path,'new-taipei-8418',$open);$bbox=[120.599,24.099,120.602,24.102];
 $a=$tc->query($bbox)['features'][0]['properties'];$b=$nt->query($bbox)['features'][0]['properties'];
 verify($a['geometry_correction']['base_m']===6 && $b['geometry_correction']===null,'Scope correction to source');
 verify($a['source_hash']!==$b['source_hash'],'Source identities differ');
 $data['features'][0]['properties']['base_m']=7;file_put_contents($open,json_encode($data));$changed=new BuildingSource($path,'taichung-8',$open);
 verify($changed->query($bbox)['features'][0]['properties']['source_hash']!==$a['source_hash'],'Correction change invalidates cache');
 verify($before===hash_file('sha256',$path),'Do not rewrite source DB');
 verify($tc->meta()['source_key']==='taichung-8','Source meta is correct');
 echo "Station source scope, cache invalidation and readonly checks passed\n";
}finally{unset($tc,$nt,$changed,$db);foreach(glob($tmp.'/*') as $p){unlink($p);}rmdir($tmp);}
