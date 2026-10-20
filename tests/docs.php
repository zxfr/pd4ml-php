<?php

/**
 * Runs the PHP snippets of docs/guide.md, each in a fresh PHP process in a scratch directory,
 * so the documentation stays correct: php tests/docs.php
 *
 * Snippets preceded by `<!-- no-test -->` (network access, licenses, web frameworks) are skipped.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
if (!getenv('PD4ML_CLASSPATH') && !getenv('PD4ML_CLASSPATH_FILE') && is_file("$root/pd4ml.classpath")) {
    putenv("PD4ML_CLASSPATH_FILE=$root/pd4ml.classpath");
}
$guide = (string) file_get_contents("$root/docs/guide.md");
preg_match_all('/(<!-- no-test -->\s*\n)?```php\n(.*?)```/s', $guide, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pd4ml-php-docs-' . getmypid();
mkdir($work, 0777, true);
file_put_contents("$work/style.css", '.note { color: #036 }');
file_put_contents("$work/report.html", '<link rel="stylesheet" href="style.css"><h1>Report</h1><p class="note">Body</p>');

$failed = $passed = $skipped = 0;
foreach ($blocks as $b) {
    $line = substr_count($guide, "\n", 0, $b[0][1]) + 1;
    if ($b[1][0] !== '' && $b[1][1] >= 0) {
        $skipped++;
        continue;
    }
    $code = $b[2][0];
    // the use statements first, then the autoloader
    preg_match_all('/^use [^;]+;\s*$/m', $code, $uses);
    $body = preg_replace('/^use [^;]+;\s*$/m', '', $code);
    $script = "<?php\n" . implode("\n", $uses[0]) . "\nrequire " . var_export("$root/src/autoload.php", true)
        . ";\nini_set('zend.assertions', '1'); ini_set('assert.exception', '1');\n" . $body;
    file_put_contents("$work/snippet.php", $script);
    $p = proc_open([PHP_BINARY, '-d', 'zend.assertions=1', "$work/snippet.php"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    if ($code === 0 && !preg_match('/(Fatal error|Warning|Deprecated):/', $out . $err)) {
        $passed++;
        echo "  ok    guide.md:$line\n";
    } else {
        $failed++;
        echo "  FAIL  guide.md:$line (exit $code)\n" . trim($out . "\n" . $err) . "\n";
    }
}
echo "\n$passed passed, $failed failed, $skipped skipped\n";
exit($failed ? 1 : 0);
