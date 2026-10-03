<?php

/*
 * devio, https://github.com/deniskoronets/devio
 * Require this file from your dev script: require __DIR__ . '/devio.phar';
 */

Phar::mapPhar('devio.phar');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dekor\\Devio\\';
    if (str_starts_with($class, $prefix)) {
        $file = 'phar://devio.phar/src/' . strtr(substr($class, strlen($prefix)), '\\', '/') . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo 'devio ', Dekor\Devio\Devio::VERSION, PHP_EOL, "require this file from your dev script: require __DIR__ . '/devio.phar';", PHP_EOL;
}

__HALT_COMPILER();
