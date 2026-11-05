<?php
declare(strict_types=1);
namespace GeoTexture {

spl_autoload_register(static function (string $class): void {
    $prefix = 'GeoTexture\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) {
            $path = __DIR__ . '/' . $name . '.php';
            if (is_file($path)) { require_once $path; }
        }
    }
});

function appConfig(): array {
    return require dirname(__DIR__) . '/config.php';
}

function createApp(?array $overrides = null): App {
    return new App(array_replace(appConfig(), $overrides ?? []));
}
}

namespace {
function geoTextureApp(): \GeoTexture\App {
    return \GeoTexture\createApp();
}
}
