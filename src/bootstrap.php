<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('SRC_PATH', BASE_PATH . '/src');
define('TEMPLATE_PATH', BASE_PATH . '/templates');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');

spl_autoload_register(static function (string $className): void {
    $prefix = 'App\\';
    if (!str_starts_with($className, $prefix)) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    $filePath = SRC_PATH . DIRECTORY_SEPARATOR . $relativePath;

    if (is_file($filePath)) {
        require $filePath;
    }
});
