<?php

/*
 * Builds dist/devio.phar: `composer build` (runs this with phar.readonly=0).
 * The phar holds src/ and an autoloader, no dependencies.
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on: run `composer build` (or php -d phar.readonly=0 build/phar.php)\n");
    exit(1);
}

$root = dirname(__DIR__);
$target = "$root/dist/devio.phar";

is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
is_file($target) && unlink($target);

$phar = new Phar($target, 0, 'devio.phar');
$phar->startBuffering();
$phar->buildFromDirectory($root, '~^' . preg_quote($root, '~') . '/(src/.+\.php|LICENSE)$~');
$phar->setStub(file_get_contents(__DIR__ . '/stub.php'));
$phar->stopBuffering();

require "$root/src/Devio.php";
printf("built dist/devio.phar: devio %s, %d files, %d KB\n", Dekor\Devio\Devio::VERSION, count($phar), filesize($target) / 1024);
