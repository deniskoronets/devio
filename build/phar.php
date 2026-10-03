<?php

/*
 * Builds dist/devio.phar: `composer build` (runs this with phar.readonly=0).
 * The phar holds src/ and an autoloader, no dependencies.
 *
 * Optional argument: the directory to build from (the pre-commit hook passes the staged files).
 * The same sources always give the same bytes, so the committed phar only changes with them.
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on: run `composer build` (or php -d phar.readonly=0 build/phar.php)\n");
    exit(1);
}

$root = dirname(__DIR__);
$from = rtrim($argv[1] ?? $root, '/');
$target = "$root/dist/devio.phar";

$files = [];
$sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$from/src", FilesystemIterator::SKIP_DOTS));
foreach ($sources as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = substr($file->getPathname(), strlen("$from/"));
    }
}
sort($files);
$files[] = 'LICENSE';

// entries carry their file's mtime: copies with a fixed one, so the bytes don't depend on the checkout
$staging = sys_get_temp_dir() . '/devio-phar-' . getmypid();
foreach ($files as $file) {
    is_dir(dirname("$staging/$file")) || mkdir(dirname("$staging/$file"), 0755, true);
    copy("$from/$file", "$staging/$file");
    touch("$staging/$file", 315532800); // 1980-01-01
}

is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
is_file($target) && unlink($target);

$phar = new Phar($target, 0, 'devio.phar');
$phar->startBuffering();
foreach ($files as $file) {
    $phar->addFile("$staging/$file", $file);
}
$phar->setStub(file_get_contents("$from/build/stub.php"));
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();

exec('rm -rf ' . escapeshellarg($staging));

preg_match("/const VERSION = '([^']+)'/", file_get_contents("$from/src/Devio.php"), $version);
printf("built dist/devio.phar: devio %s, %d files, %d KB\n", $version[1] ?? '?', count($phar), filesize($target) / 1024);
