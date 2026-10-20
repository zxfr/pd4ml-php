<?php

/**
 * Rebuilds lib/pd4ml-bridge.jar from java/src (Java 8 bytecode, so any Java 8+ runtime can run it).
 *
 *   php tools/build-bridge.php        # needs a JDK: javac and jar from JAVA_HOME or PATH
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$bin = getenv('JAVA_HOME') ? rtrim(getenv('JAVA_HOME'), '/\\') . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR : '';
$exe = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
$build = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pd4ml-bridge-build-' . getmypid();
mkdir($build, 0777, true);

$sources = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/java/src", FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() === 'java') {
        $sources[] = $file->getPathname();
    }
}

$run = static function (array $cmd): void {
    $p = proc_open($cmd, [1 => STDOUT, 2 => STDERR], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($p) || proc_close($p) !== 0) {
        fwrite(STDERR, 'failed: ' . implode(' ', $cmd) . PHP_EOL);
        exit(1);
    }
};
$run(array_merge(["{$bin}javac$exe", '--release', '8', '-d', $build], $sources));
$run(["{$bin}jar$exe", '--create', '--file', "$root/lib/pd4ml-bridge.jar", '-C', $build, '.']);

$rm = static function (string $dir) use (&$rm): void {
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? $rm("$dir/$f") : unlink("$dir/$f");
        }
    }
    rmdir($dir);
};
$rm($build);
echo "lib/pd4ml-bridge.jar: ", filesize("$root/lib/pd4ml-bridge.jar"), " bytes", PHP_EOL;
