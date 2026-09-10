<?php

$base = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $base;
$_SERVER['APP_BASE_PATH'] = $base;
putenv('APP_BASE_PATH='.$base);

spl_autoload_register(static function (string $class) use ($base): bool {
    $prefixes = [
        'App\\' => $base.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR,
        'Database\\Factories\\' => $base.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'factories'.DIRECTORY_SEPARATOR,
        'Database\\Seeders\\' => $base.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders'.DIRECTORY_SEPARATOR,
        'Tests\\' => $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR,
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;

            return true;
        }
    }

    return false;
}, prepend: true);

require $base.'/vendor/autoload.php';
