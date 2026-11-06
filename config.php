<?php
declare(strict_types=1);

$state = getenv('GEOTEXTURE_STATE_DIR') ?: __DIR__ . '/db';
return [
    'source_db' => getenv('GEOTEXTURE_SOURCE_DB') ?: __DIR__ . '/db/F_新北建物8418.db',
    'state_dir' => $state,
    'artifact_dir' => rtrim($state, '/\\') . '/artifacts',
    'recipe_key' => 'texture-v1',
    'center' => ['longitude' => 121.41798, 'latitude' => 25.175544],
    'zoom' => 15,
];
