<?php

/**
 * Minimal PSR-4 autoloader for using the Bahaa Gateway SDK WITHOUT Composer.
 *
 * Usage in a plain PHP project:
 *   require __DIR__ . '/path/to/bahaa-gateway-php-sdk/autoload.php';
 *   $client = new \BahaaGateway\BahaaClient('pk_...', 'sk_...');
 *
 * If you use Composer, require "vendor/autoload.php" instead of this file.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'BahaaGateway\\';
    $baseDir = __DIR__ . '/src/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
