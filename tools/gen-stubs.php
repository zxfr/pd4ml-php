<?php

/**
 * Generates the API declarations from the PD4ML classes:
 *
 * - src/PD4MLApi.php: `@method` declarations of every `com.pd4ml.PD4ML` method, so an IDE
 *   completes and checks the Java methods called on {@see \Pd4ml\PD4ML}
 * - docs/api.md: the public API of every exported class, with PHP types
 *
 *   php tools/gen-stubs.php [classpath-file]      # default: pd4ml.classpath; javap from JAVA_HOME or PATH
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cpFile = $argv[1] ?? "$root/pd4ml.classpath";
$entries = array_filter(array_map('trim', preg_split('/\R|' . preg_quote(PATH_SEPARATOR, '/') . '/', (string) file_get_contents($cpFile))));
$cp = implode(PATH_SEPARATOR, $entries);
$javap = (getenv('JAVA_HOME') ? rtrim(getenv('JAVA_HOME'), '/\\') . '/bin/' : '') . 'javap' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

/** The classes documented: PHP accessor => Java class. */
$classes = [
    'PD4ML' => 'com.pd4ml.PD4ML',
    'Constants' => 'com.pd4ml.Constants',
    'LayoutEngine' => 'com.pd4ml.Constants$LayoutEngine',
    'ErrorPolicy' => 'com.pd4ml.Constants$ErrorPolicy',
    'PageSize' => 'com.pd4ml.PageSize',
    'PageMargins' => 'com.pd4ml.PageMargins',
    'Dimensions' => 'com.pd4ml.Dimensions',
    'Units' => 'com.pd4ml.Dimensions$Units',
    'PdfSpec' => 'com.pd4ml.PdfSpec',
    'PdfDocument' => 'com.pd4ml.PdfDocument',
    'PdfDocumentBase' => 'com.pd4ml.pdf.PdfDocumentBase',
    'ProgressListener' => 'com.pd4ml.ProgressListener',
    'ResourceProvider' => 'com.pd4ml.ResourceProvider',
    'CustomTag' => 'com.pd4ml.CustomTag',
    'StatusMessage' => 'com.pd4ml.StatusMessage',
];
$javaToPhp = array_flip($classes);

function phpType(string $java, array $names): string
{
    $t = preg_replace('/<.*>/', '', trim($java));
    static $map = [
        'void' => 'void', 'boolean' => 'bool', 'int' => 'int', 'long' => 'int', 'short' => 'int', 'byte' => 'int',
        'char' => 'string', 'float' => 'float', 'double' => 'float', 'java.lang.String' => 'string',
        'java.lang.Object' => 'mixed', 'byte[]' => 'string', 'byte[][]' => 'string[]',
        'java.util.Map' => 'array|JavaObject', 'java.io.InputStream' => 'Bytes|JavaObject',
        'java.io.OutputStream' => 'Output|JavaObject', 'java.net.URL' => 'string|JavaObject',
        'java.io.File' => 'string|JavaObject',
    ];
    if (isset($map[$t])) {
        return $map[$t];
    }
    if (str_ends_with($t, '[]')) {
        return phpType(substr($t, 0, -2), $names) . '[]';
    }
    return 'JavaObject';
}

function splitParams(string $s): array
{
    $out = [];
    $depth = 0;
    $cur = '';
    foreach (str_split($s) as $ch) {
        $depth += $ch === '<' ? 1 : ($ch === '>' ? -1 : 0);
        if ($ch === ',' && $depth === 0) {
            $out[] = trim($cur);
            $cur = '';
        } else {
            $cur .= $ch;
        }
    }
    if (trim($cur) !== '') {
        $out[] = trim($cur);
    }
    return $out;
}

/** [fields, constructors, methods] of a javap listing. */
function members(string $listing, array $names): array
{
    $fields = $ctors = $methods = [];
    foreach (explode("\n", $listing) as $line) {
        if (!preg_match('/^\s*public\s+((?:(?:static|final|abstract|synchronized|native)\s+)*)(?:<[^>]+>\s+)?(.*?);\s*$/', $line, $m)) {
            continue;
        }
        $static = str_contains($m[1], 'static');
        $rest = preg_replace('/\s+throws\s+.*$/', '', $m[2]);
        if (preg_match('/^(?:(\S+(?:<.*>)?)\s+)?([\w.$]+)\((.*)\)$/', $rest, $c)) {
            $params = array_map(fn ($p) => phpType($p, $names), splitParams($c[3]));
            if ($c[1] === '') {
                $ctors[] = $params;
            } else {
                $methods[] = [$static, $c[2], $params, phpType($c[1], $names)];
            }
        } else {
            $pos = strrpos($rest, ' ');
            $fields[] = [$static, substr($rest, $pos + 1), phpType(substr($rest, 0, $pos), $names)];
        }
    }
    return [$fields, $ctors, $methods];
}

$api = [];
foreach ($classes as $php => $java) {
    $cmd = [$javap, '-public', '-cp', $cp, $java];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $listing = stream_get_contents($pipes[1]);
    proc_close($p);
    if (!str_contains($listing, '{')) {
        fwrite(STDERR, "javap failed for $java\n");
        exit(1);
    }
    $api[$php] = members($listing, $javaToPhp);
}

// ---------------------------------------------------------------------- src/PD4MLApi.php
$wrapped = ['useHtmlRenderer', 'readHTML', 'writePDF', 'writeDOCX', 'writeRTF', 'renderAsImages', 'monitorProgressWith'];
$lines = [];
foreach ($api['PD4ML'][2] as [$static, $name, $params, $ret]) {
    if (in_array($name, $wrapped, true)) {
        continue;
    }
    $args = implode(', ', array_map(fn ($t, $i) => "$t \$arg$i", $params, array_keys($params)));
    $lines[] = ' * @method ' . ($static ? 'static ' : '') . "$ret $name($args)";
}
$php = <<<PHP
<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * The Java methods of `com.pd4ml.PD4ML` available on {@see PD4ML} (besides those it defines with
 * PHP I/O). Generated by tools/gen-stubs.php from the Java API - do not edit.
 *
 * Java `null` is PHP `null`; a `JavaObject` is any Java object (`Java::PageSize()->A4`...).
 *

PHP;
$php .= implode("\n", $lines) . "\n */\nfinal class PD4MLApi\n{\n    private function __construct()\n    {\n    }\n}\n";
file_put_contents("$root/src/PD4MLApi.php", $php);

// --------------------------------------------------------------------------- docs/api.md
$md = "# PD4ML API reference (PHP)\n\n"
    . "_Generated by `tools/gen-stubs.php` from the PD4ML Java classes - do not edit._\n\n"
    . "Each class is reached through `Pd4ml\\Java` (`Java::PageSize()`): its *static* members on that class "
    . "object (`Java::PageSize()->A4`), the others on the objects it creates or returns (`->rotate()`). "
    . "Java types appear as PHP types: "
    . "`JavaObject` is any Java object; `Bytes`/`Output` are the binary input/output markers. "
    . "Descriptions of each method are in the [PD4ML manual](https://pd4ml.com/docs/pd4ml-manual.html).\n\n";
foreach ($api as $php => [$fields, $ctors, $methods]) {
    $md .= "## $php\n\n`" . str_replace('$', '.', $classes[$php]) . "`" . ($php === 'PD4ML' ? ' — use the `Pd4ml\\PD4ML` wrapper' : " — `Java::$php()`") . "\n\n";
    if ($fields) {
        $md .= "| Field | Type | |\n|---|---|---|\n";
        foreach ($fields as [$static, $name, $type]) {
            $md .= "| `->$name` | `$type` | " . ($static ? 'static' : '') . " |\n";
        }
        $md .= "\n";
    }
    if ($ctors || $methods) {
        $md .= "```php\n";
        foreach ($ctors as $params) {
            $md .= "->new(" . implode(', ', array_map(fn ($t, $i) => "$t \$arg$i", $params, array_keys($params))) . "): JavaObject\n";
        }
        foreach ($methods as [$static, $name, $params, $ret]) {
            $md .= ($static ? 'static ->' : '->') . "$name(" . implode(', ', array_map(fn ($t, $i) => "$t \$arg$i", $params, array_keys($params))) . "): $ret\n";
        }
        $md .= "```\n\n";
    }
}
file_put_contents("$root/docs/api.md", $md);
$count = array_sum(array_map(fn ($a) => count($a[2]), $api));
echo "src/PD4MLApi.php, docs/api.md: $count methods in " . count($api) . " classes", PHP_EOL;
