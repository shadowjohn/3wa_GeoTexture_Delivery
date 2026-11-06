<?php
declare(strict_types=1);

$state = getenv('GEOTEXTURE_STATE_DIR') ?: __DIR__ . '/db';
return [
    'source_db' => getenv('GEOTEXTURE_SOURCE_DB') ?: __DIR__ . '/db/F_新北建物8418.db',
    'source_key' => 'new-taipei-8418',
    'sources' => [
        'new-taipei-8418' => ['name'=>'新北市', 'path'=>getenv('GEOTEXTURE_SOURCE_DB') ?: __DIR__.'/db/F_新北建物8418.db', 'center'=>['longitude'=>121.41798,'latitude'=>25.175544]],
        'taichung-8' => ['name'=>'臺中市', 'path'=>getenv('GEOTEXTURE_TAICHUNG_DB') ?: '/var/www/html/easymap_server/uploads/8/8.db', 'center'=>['longitude'=>120.6483,'latitude'=>24.1194]],
    ],
    'station_openings' => __DIR__.'/data/station-openings.geojson',
    'state_dir' => $state,
    'artifact_dir' => rtrim($state, '/\\') . '/artifacts',
    'recipe_key' => 'texture-v1',
    'center' => ['longitude' => 121.41798, 'latitude' => 25.175544],
    'zoom' => 15,
];
