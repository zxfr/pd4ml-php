<?php

/**
 * The tests: php tests/run.php [filter]
 *
 * They need a PD4ML build: PD4ML_CLASSPATH / PD4ML_CLASSPATH_FILE, ./pd4ml.classpath, or one
 * installed with `php bin/pd4ml install`. PD4ML_NETWORK_TESTS=1 adds a test that downloads the
 * latest release into a temporary directory.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Pd4ml\Bridge;
use Pd4ml\Bytes;
use Pd4ml\Installer;
use Pd4ml\Java;
use Pd4ml\JavaException;
use Pd4ml\JavaObject;
use Pd4ml\PD4ML;
use Pd4ml\Pdf;
use Pd4ml\Runtime;

$root = dirname(__DIR__);
if (!getenv('PD4ML_CLASSPATH') && !getenv('PD4ML_CLASSPATH_FILE') && is_file("$root/pd4ml.classpath")) {
    putenv("PD4ML_CLASSPATH_FILE=$root/pd4ml.classpath");
}

$tests = [];
function test(string $name, callable $body): void
{
    $GLOBALS['tests'][$name] = $body;
}

function check(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tmpdir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pd4ml-php-test-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0777, true);
    return $dir;
}

const HTML = '<html><head><title>Test document</title><style>h1 { color: #036 }</style></head>'
    . '<body><h1>PD4ML for PHP</h1><p>Hello, <b>world</b>.</p></body></html>';

// ------------------------------------------------------------------------------------- runtime

test('version', function () {
    check(Runtime::version() !== '');
    check(PD4ML::getVersion() === Runtime::version(), 'static Java methods on the wrapper class');
});

test('default engine is v42', function () {
    check(PD4ML::DEFAULT_ENGINE === 'v42');
    if (Runtime::hasLayoutEngines()) {
        check((new PD4ML())->engine === 'v42');
        check((new PD4ML(null, null, 'v40'))->engine === 'v40');
        $pd = new PD4ML();
        $pd->useHtmlRenderer(Java::LayoutEngine()->v40, 1000);
        check($pd->engine === 'v40');
    } else {
        check(@(new PD4ML())->engine === null);
    }
});

test('Java classes and values', function () {
    $a4 = Java::PageSize()->A4;
    check($a4 instanceof JavaObject && $a4->javaClassName() === 'com.pd4ml.PageSize');
    check($a4->width > 0 && is_float($a4->width));
    check($a4->rotate()->width === $a4->height, 'instance methods and fields');
    check((string) Java::PdfSpec()->PDFA_2B === 'PDF/A-2b');
    check(Java::Constants()->AllowPrint > 0);
    check(Java::PageSize()->getInstance('no-such-size') === null, 'Java null is PHP null');
    check(Java::cls('java.lang.Integer')->parseInt('42') === 42);
    $map = Java::cls('java.util.HashMap')->new();
    $map->put('k', 'v');
    check($map->get('k') === 'v');
    check(Java::Units()->MM->enumName() === 'MM');
});

// ------------------------------------------------------------------------------------- output

test('PDF as a string, to a file and to a stream', function () {
    $pd = new PD4ML();
    $pd->setPageSize(Java::PageSize()->A4);
    $pd->setPageMargins(Java::PageMargins()->new(10, 10, 10, 10, Java::Units()->MM));
    $pd->readHtmlString(HTML);
    $pdf = $pd->writePDF();
    check(str_starts_with($pdf, '%PDF-'));
    check(Pdf::open($pdf)->getNumberOfPages() === 1);

    $dir = tmpdir();
    $pd = new PD4ML();
    $pd->readHtmlString(HTML);
    check($pd->writePDF("$dir/a.pdf") === null);
    check(str_starts_with((string) file_get_contents("$dir/a.pdf"), '%PDF-'));

    $stream = fopen('php://memory', 'w+b');
    $pd = new PD4ML();
    $pd->readHtmlString(HTML);
    $pd->writePDF($stream);
    rewind($stream);
    check(str_starts_with((string) stream_get_contents($stream), '%PDF-'));
});

test('read from a path with relative resources, bytes and a stream', function () {
    $dir = tmpdir();
    file_put_contents("$dir/style.css", 'p { color: red }');
    file_put_contents("$dir/doc.html", '<link rel="stylesheet" href="style.css"><p>styled</p>');
    $pd = new PD4ML();
    $pd->readHTML("$dir/doc.html");
    check(str_starts_with($pd->writePDF(), '%PDF-'));

    $pd = new PD4ML();
    $pd->readHTML(new Bytes(HTML), $dir);
    check(str_starts_with($pd->writePDF(), '%PDF-'));

    $stream = fopen("$dir/doc.html", 'rb');
    $pd = new PD4ML();
    $pd->readHTML($stream, $dir);
    check(str_starts_with($pd->writePDF(), '%PDF-'));
});

test('PDF/A by PdfSpec and keyword', function () {
    $pd = new PD4ML();
    $pd->readHtmlString(HTML);
    check(str_contains($pd->writePDF(null, Java::PdfSpec()->PDFA_2B), 'pdfaid'));
    check(Runtime::pdfSpec('pdf/a-3b')->equals(Java::PdfSpec()->PDFA_3B));
    try {
        Runtime::pdfSpec('pdf/a-9z');
        check(false, 'unknown keyword accepted');
    } catch (InvalidArgumentException) {
    }
});

test('DOCX, RTF and PNG', function () {
    $pd = new PD4ML();
    $pd->readHtmlString(HTML);
    $docx = $pd->writeDOCX();
    check(str_starts_with($docx, 'PK'));
    $rtf = $pd->writeRTF();
    check(str_starts_with($rtf, '{\\rtf'));
    $pages = $pd->renderPng();
    check(count($pages) >= 1 && str_starts_with($pages[0], "\x89PNG"));
});

test('header, footer, dynamic data and render info', function () {
    $pd = new PD4ML();
    $pd->setDynamicData(['customer' => 'Acme']);
    $pd->setPageFooter('<div>$[customer] page $[page] of $[total]</div>', 24, '1+');
    $pd->generateBookmarksFromHeadings(true);
    $pd->readHtmlString('<h1>A</h1><p style="page-break-after:always">x</p><h1>B</h1>');
    $pdf = $pd->writePDF();
    check((int) $pd->getLastRenderInfo(Java::Constants()->PD4ML_TOTAL_PAGES) === 2);
    check(str_contains((string) Pdf::open($pdf)->dumpOutlines(), 'B'));
});

test('password protection', function () {
    $pd = new PD4ML();
    $pd->setPermissions('secret', Java::Constants()->AllowPrint);
    $pd->readHtmlString(HTML);
    check(str_contains($pd->writePDF(), '/Encrypt'));
});

test('progress callback', function () {
    $seen = [];
    $pd = new PD4ML();
    $pd->monitorProgressWith(function (int $id, int $progress, ?string $note, int $msec) use (&$seen) {
        $seen[] = $id;
    });
    $pd->readHtmlString(HTML);
    $pd->writePDF();
    check($seen !== [], 'progress reported');   // v42: DOC_WRITE_BEGIN only (as of 4.2.0)
});

test('callback exceptions reach PHP', function () {
    $pd = new PD4ML();
    $pd->monitorProgressWith(function () {
        throw new LogicException('from the callback');
    });
    $pd->readHtmlString(HTML);
    try {
        $pd->writePDF();
        check(false, 'callback exception lost');
    } catch (LogicException $e) {
        check($e->getMessage() === 'from the callback');
    }
});

test('merge, append and encrypt PDFs', function () {
    $one = Pdf::htmlToPdf(new Bytes(HTML));
    check(Pdf::open(Pdf::merge($one, $one))->getNumberOfPages() === 2);
    $doc = Pdf::open($one);
    $doc->append(Pdf::open($one));
    $both = Pdf::write($doc, null, 'secret');
    check(str_contains($both, '/Encrypt'));
});

test('Java exceptions', function () {
    try {
        $pd = new PD4ML();
        $pd->readHTML('file:///no/such/file.html');
        check(false, 'no exception');
    } catch (JavaException $e) {
        check($e->isInstanceOf('java.io.IOException'), $e->javaClass);
        check($e->getJavaMessage() !== '');
    }
    try {
        (new PD4ML())->noSuchMethod();
        check(false, 'no exception');
    } catch (JavaException $e) {
        check($e->isInstanceOf('java.lang.NoSuchMethodException'));
    }
});

// ----------------------------------------------------------------------------------- installer

test('version ordering', function () {
    $versions = ['4.0.0-SNAPSHOT', '4.0.0', '4.0.6fx1', '4.0.9fx5', '4.0.15fx1-jakarta', '4.0.20-jakarta',
        '4.0.23fx1a1', '4.0.23fx1', '4.0.23fx2', '4.0.23fx2-SNAPSHOT', '4.1.0', '4.1.1',
        '4.0.24-SNAPSHOT', '4.1.2-SNAPSHOT', '3.9.9', 'bogus'];
    check(Installer::selectVersion($versions) === '4.1.1');
    check(Installer::selectVersion($versions, 4, true) === '4.1.2-SNAPSHOT');
    check(Installer::selectVersion($versions, 4, false, true) === '4.0.20-jakarta');
    check(Installer::selectVersion($versions, 3) === '3.9.9');
    check(Installer::versionKey('4.0.23fx1a1') < Installer::versionKey('4.0.23fx1'));
    check(Installer::versionKey('4.0.23fx2-SNAPSHOT') < Installer::versionKey('4.0.23fx2'));
    check(Installer::versionKey('bogus') === null);
});

if (getenv('PD4ML_NETWORK_TESTS') === '1') {
    test('install and convert (network)', function () {
        $home = tmpdir();
        $script = 'require ' . var_export(dirname(__DIR__) . '/src/autoload.php', true) . ';'
            . '$jar = Pd4ml\Installer::install("latest", ["quiet" => true]);'
            . 'if (count(Pd4ml\Installer::installedClasspath()) !== 4) exit(3);'
            . '$pd = @new Pd4ml\PD4ML(); $pd->setPermissions("s", Pd4ml\Java::Constants()->AllowPrint);'
            . '$pd->readHtmlString("<p>x</p>"); echo str_contains($pd->writePDF(), "/Encrypt") ? "ok" : "no";';
        $env = ['PD4ML_HOME' => $home, 'JAVA_HOME' => (string) getenv('JAVA_HOME'),
            'PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot')];
        $p = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($p);
        check($code === 0 && $out === 'ok', "exit $code: $out $err");
    });
}

// -------------------------------------------------------------------------------------- runner

$filter = $argv[1] ?? '';
$failed = 0;
$passed = 0;
foreach ($tests as $name => $body) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $body();
        $passed++;
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
Bridge::shutdown();
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
