<?php

/*
 * Fails if dist/devio.phar doesn't hold exactly src/, LICENSE and build/stub.php: CI runs it, so a phar
 * committed without the pre-commit hook (--no-verify, an edit on GitHub) is caught. Compares contents,
 * not bytes: PHP versions write the phar's checksums differently.
 */

$root = dirname(__DIR__);
$phar = new Phar("$root/dist/devio.phar");
$prefix = "phar://$root/dist/devio.phar/";

$inPhar = [];
foreach (new RecursiveIteratorIterator($phar) as $entry) {
    $inPhar[substr($entry->getPathname(), strlen($prefix))] = file_get_contents($entry->getPathname());
}

$expected = ['LICENSE' => file_get_contents("$root/LICENSE")];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src", FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $expected[substr($file->getPathname(), strlen("$root/"))] = file_get_contents($file->getPathname());
    }
}

$stale = array_keys(array_diff_assoc($expected, $inPhar) + array_diff_key($inPhar, $expected));
$stub = fn (string $code) => rtrim(preg_replace('/\s*\?>\s*$/', '', $code)); // Phar appends a closing tag
if ($stub($phar->getStub()) !== $stub(file_get_contents("$root/build/stub.php"))) {
    $stale[] = 'build/stub.php';
}

if ($stale) {
    sort($stale);
    fwrite(STDERR, "dist/devio.phar is out of date (" . implode(', ', $stale) . "): run `composer build` and commit it\n");
    exit(1);
}
echo "dist/devio.phar matches the sources\n";
