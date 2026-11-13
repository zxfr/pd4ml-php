<?php

/**
 * The tests: php tests/run.php [filter]
 *
 * They need a PD4ML build: PD4ML_CLASSPATH / PD4ML_CLASSPATH_FILE, ./pd4ml.classpath, or one
 * installed with `php bin/pd4ml install`. PD4ML_NETWORK_TESTS=1 adds a test that downloads the
 * default build (PD4ML 4.2) into a temporary directory. PD4ML_TEST_LICENSE (a license file path) licenses
 * the DOM API tests; with PDFBox 3 on the classpath they check the PDF text as well.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Pd4ml\Bridge;
use Pd4ml\Bytes;
use Pd4ml\Dom;
use Pd4ml\InstallException;
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

test('methods of JDK-internal classes (a public class of an unexported package)', function () {
    // Charset.forName gives sun.nio.cs.UTF_8; its newEncoder() is called through java.nio.charset.Charset
    $encoder = Java::cls('java.nio.charset.Charset')->forName('UTF-8')->newEncoder();
    check($encoder->charset()->name() === 'UTF-8');
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

test('Markdown', function () {
    $pd = new PD4ML();
    $pd->readHtmlString('<h1>Markdown</h1><p>Hello, <b>world</b>.</p>');
    // (without a DMS/UA license, evaluation notes go between the blocks)
    $md = $pd->writeMarkdown(null, true);
    check(str_contains($md, '<!-- page 1 -->'));
    check(str_contains($md, '# Markdown'));
    check(str_contains($md, 'Hello, **world**.'));
    $plain = $pd->writeMarkdown();
    check(str_contains($plain, '# Markdown') && !str_contains($plain, '<!-- page'));
    check(Java::Constants()->MARKDOWN === 'markdown');
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
    check($seen !== [], 'progress reported');   // v42 reports while reading too (newer 4.2.0 builds), not only at DOC_WRITE_BEGIN
});

test('callback exceptions reach PHP', function () {
    $pd = new PD4ML();
    $pd->monitorProgressWith(function () {
        throw new LogicException('from the callback');
    });
    try {
        // the first progress event fails the call that caused it: reading or writing, by PD4ML version
        $pd->readHtmlString(HTML);
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

// ------------------------------------------------- e-mail, saved pages, resource restriction (4.2)

/** Skips a test on a PD4ML build without the API it tests (the DOM API etc. are new in 4.2). */
function requires42(): void
{
    if (!Runtime::hasLayoutEngines()) {
        throw new SkipTest('needs PD4ML 4.2');
    }
}

final class SkipTest extends RuntimeException
{
}

/** A converter with the license in PD4ML_TEST_LICENSE (a license file path), if set. */
function licensed(): PD4ML
{
    $file = getenv('PD4ML_TEST_LICENSE');
    return $file ? new PD4ML((string) Runtime::toUrl($file)) : new PD4ML();
}

/** The text of a PDF, if PDFBox 3 is on the classpath; else null. */
function pdfText(string $pdf): ?string
{
    try {
        $doc = Java::cls('org.apache.pdfbox.Loader')->loadPDF(new Bytes($pdf));
    } catch (JavaException) {
        return null;
    }
    try {
        return (string) Java::cls('org.apache.pdfbox.text.PDFTextStripper')->new()->getText($doc);
    } finally {
        $doc->close();
    }
}

const EML = "From: Alice <alice@example.com>\r\nTo: Bob <bob@example.com>\r\nSubject: Quarterly figures\r\n"
    . "Date: Fri, 06 Nov 2026 10:00:00 +0100\r\nMessage-ID: <1@example.com>\r\nMIME-Version: 1.0\r\n"
    . "Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n"
    . "--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHello from the plain part\r\n"
    . "--b1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
    . "<html><body><p id=\"m\">Hello from the <b>HTML</b> part</p></body></html>\r\n--b1--\r\n";

const MHT = "From: <Saved by Blink>\r\nSnapshot-Content-Location: https://example.com/page.html\r\n"
    . "Subject: Saved page\r\nMIME-Version: 1.0\r\n"
    . "Content-Type: multipart/related; type=\"text/html\"; boundary=\"b2\"\r\n\r\n"
    . "--b2\r\nContent-Type: text/html; charset=utf-8\r\nContent-Location: https://example.com/page.html\r\n\r\n"
    . "<html><head><title>Saved page</title><link rel=\"stylesheet\" href=\"https://example.com/s.css\"></head>"
    . "<body><h1 id=\"h\">A saved web page</h1></body></html>\r\n"
    . "--b2\r\nContent-Type: text/css\r\nContent-Location: https://example.com/s.css\r\n\r\n"
    . "h1 { color: rgb(0, 128, 0) }\r\n--b2--\r\n";

test('readEML: path, bytes and stream', function () {
    requires42();
    $dir = tmpdir();
    file_put_contents("$dir/message.eml", EML);
    $sources = ["$dir/message.eml", new Bytes(EML), fopen("$dir/message.eml", 'rb')];
    foreach ($sources as $source) {
        $pd = new PD4ML();
        $pd->readEML($source);
        check($pd->getDocumentTitle() === 'Quarterly figures', 'the subject is the title');
        check(str_contains($pd->getDocument()->getBody()->getTextContent(), 'Hello from the HTML part'),
            'the HTML part is the body');
        check(str_starts_with($pd->writePDF(), '%PDF-'));
    }
    $pd = new PD4ML();
    $pd->readHTML("$dir/message.eml");          // recognized by readHTML too
    check($pd->getDocumentTitle() === 'Quarterly figures');
});

test('readMHT', function () {
    requires42();
    $dir = tmpdir();
    file_put_contents("$dir/page.mht", MHT);
    foreach (["$dir/page.mht", new Bytes(MHT)] as $source) {
        $pd = new PD4ML();
        $pd->readMHT($source);
        check($pd->getDocumentTitle() === 'Saved page');
        $doc = $pd->getDocument();
        check($doc->getElementById('h')->getTextContent() === 'A saved web page');
        check($pd->layout()->getComputedStyle($doc->getElementById('h'), 'color') === '#008000',
            'the style sheet saved in the file applies');
        check(str_starts_with($pd->writePDF(), '%PDF-'));
    }
});

test('addAllowedResourceLocation', function () {
    requires42();
    $dir = tmpdir();
    mkdir("$dir/assets");
    mkdir("$dir/other");
    file_put_contents("$dir/assets/style.css", '#t { color: rgb(255, 0, 0) }');
    $html = '<link rel="stylesheet" href="assets/style.css"><p id="t">text</p>';
    $color = function (string|array|null $allowed) use ($dir, $html): ?string {
        $pd = new PD4ML();
        if ($allowed !== null) {
            $pd->addAllowedResourceLocation($allowed);
        }
        $pd->readHtmlString($html, $dir);
        return $pd->layout()->getComputedStyle($pd->getDocument()->getElementById('t'), 'color');
    };
    check($color(null) === '#ff0000', 'loads from anywhere by default');
    check($color("$dir/assets/") === '#ff0000', 'an allowed local path');
    check($color('https://cdn.example.com/, ' . str_replace('/', DIRECTORY_SEPARATOR, "$dir/assets/")) === '#ff0000',
        'a comma-separated list, a path with the platform separator');
    $url = (string) Runtime::toUrl($dir);                       // file:/C:/... or file:/tmp/...
    check($color(['https://cdn.example.com/', rtrim($url, '/') . '/*/']) === '#ff0000',
        'an array of locations, a file: URL with a * pattern');
    check($color("$dir/other/") !== '#ff0000', 'a location outside is not loaded');

    // (as of 4.2.0 a rejected image throws with PEDANTIC; a rejected style sheet does not)
    $pd = new PD4ML();
    $pd->setErrorPolicy(Java::ErrorPolicy()->PEDANTIC);
    $pd->addAllowedResourceLocation("$dir/other/");
    try {
        $pd->readHtmlString('<img src="assets/logo.png">', $dir);
        $pd->writePDF();
        check(false, 'a rejected resource with PEDANTIC: no exception');
    } catch (JavaException $e) {
        check($e->isInstanceOf('java.io.IOException'), $e->javaClass);
    }
});

test('addMetadata with an XMP namespace', function () {
    requires42();
    $pd = new PD4ML();
    $pd->addMetadata('http://example.com/ns/invoice/1.0/', 'inv', 'InvoiceNumber', 'INV-2026-0042');
    $pd->readHtmlString(HTML);
    $pdf = $pd->writePDF(null, 'pdf/a-2b');
    check(str_contains($pdf, 'INV-2026-0042') && str_contains($pdf, 'http://example.com/ns/invoice/1.0/'),
        'the property is in the XMP metadata');
});

// ---------------------------------------------------------------------------------- DOM API (4.2)

test('DOM: query and change the document', function () {
    requires42();
    $pd = licensed();
    $pd->generateBookmarksFromHeadings(true);
    $pd->readHtmlString('<!DOCTYPE html><html><head><title>Invoice</title></head><body>'
        . '<h1 id="title" class="big main">Invoice draft</h1><!-- note -->'
        . '<table><tbody id="items"><tr><td>sample</td></tr></tbody></table>'
        . '<p class="item">one</p><p class="item">two</p><p class="item draft-only">three</p></body></html>');
    $doc = $pd->getDocument();
    check($doc instanceof Dom\Document && $doc->getTitle() === 'Invoice');
    check($doc->getDoctype()?->getName() === 'html' && !$doc->isQuirksMode());

    $items = $doc->querySelectorAll('p.item');
    check(is_array($items) && array_is_list($items) && count($items) === 3, 'querySelectorAll: a PHP list');
    check($items[0] instanceof Dom\Element && $items[1]->getTextContent() === 'two');
    check(count($doc->getElementsByTagName('p')) === 3 && count($doc->getElementsByClassName('item draft-only')) === 1);
    check($doc->querySelector('p.nothing') === null && $doc->getElementById('nothing') === null);

    $h1 = $doc->getElementById('title');
    check($h1->getTagName() === 'h1' && $h1->getAttributes() === ['id' => 'title', 'class' => 'big main']);
    check($h1->getClassList() === ['big', 'main'] && $h1->hasClass('main'));
    check($h1->getNextSibling() instanceof Dom\Comment && $h1->getNextElementSibling()->getTagName() === 'table');
    check($h1->getParentElement()->equals($doc->getBody()) && $doc->getBody()->contains($h1));
    check(!$h1->getParentElement()->equals($h1));
    check($h1->getFirstChild() instanceof Dom\Text && $h1->getFirstChild()->getData() === 'Invoice draft');
    check($doc->querySelector('td')->closest('tbody')->getId() === 'items' && $items[2]->matches('.draft-only'));

    // the edits
    $h1->setTextContent('Invoice 2026-0042');
    $h1->toggleClass('big');
    check($h1->getClassName() === 'main');
    $doc->getElementById('items')->setInnerHTML('<tr><td>Consulting</td></tr><tr><td>Travel</td></tr>');
    check(count($doc->querySelectorAll('#items tr')) === 2);
    foreach ($doc->querySelectorAll('.draft-only') as $e) {
        $e->remove();
    }
    $note = $doc->createElement('p');
    $note->setAttribute('id', 'note');
    $note->appendChild($doc->createTextNode('Prices include VAT.'));
    $doc->getBody()->insertBefore($note, $doc->getBody()->getFirstElementChild());
    check($doc->getBody()->getFirstElementChild()->getId() === 'note');
    $copy = $note->cloneNode(true);
    check($copy->getTextContent() === 'Prices include VAT.' && $copy->getParentNode() === null);
    check(str_contains($doc->toHtml(), '<td>Travel</td>') && !str_contains($doc->toHtml(), 'three'));

    // ... are in the output
    $pdf = $pd->writePDF();
    check(str_contains((string) Pdf::open($pdf)->dumpOutlines(), 'Invoice 2026-0042'), 'the bookmark of the edited heading');
    $text = pdfText($pdf);
    if ($text !== null) {
        check(str_contains($text, 'Invoice 2026-0042') && str_contains($text, 'Prices include VAT.')
            && str_contains($text, 'Travel') && !str_contains($text, 'three') && !str_contains($text, 'Invoice draft'),
            "the edits in the PDF text:\n$text");
    }
});

test('DOM: layout', function () {
    requires42();
    $pd = licensed();
    $pd->setPageSize(Java::PageSize()->A4);
    $pd->readHtmlString('<h1 id="h" style="font-size:20px">Title</h1><p id="a">first</p>'
        . '<p id="b" style="display:none">hidden</p><p id="c">last</p>');
    $doc = $pd->getDocument();
    $layout = $pd->layout();
    check($layout instanceof Dom\Layout && $layout->getPageCount() === 1);
    check(abs($layout->getPageWidth(1) - 595.0) < 1 && abs($layout->getPageHeight(1) - 842.0) < 1);
    check($layout->getPageNumber(1) === 1);

    $boxes = $layout->getBoxes($doc->getElementById('h'));
    check(count($boxes) === 1 && $boxes[0] instanceof Dom\LayoutBox, 'getBoxes: a PHP list of boxes');
    [$box] = $boxes;
    check($box->page === 1 && $box->getPage() === 1 && $box->getX() > 0 && $box->getY() > 0
        && $box->getWidth() > 0 && $box->getHeight() > 0, (string) $box);
    check(str_starts_with((string) $box, 'page 1: '));
    check($layout->getPages($doc->getElementById('c')) === [1]);
    check($layout->getBoxes($doc->getElementById('b')) === [] && $layout->getPages($doc->getElementById('b')) === []);
    check($layout->getComputedStyle($doc->getElementById('h'), 'font-size') !== null);
    check($layout->getComputedStyle($doc->getElementById('b'), 'display') === null, 'not displayed');
    $hit = $layout->elementAt(1, $box->x + $box->width / 2, $box->y + $box->height / 2);
    check($hit !== null && $hit->closest('h1')?->getId() === 'h', 'elementAt');

    // a change, then a new layout
    $doc->getElementById('c')->setAttribute('style', 'page-break-before: always');
    check($layout->getPageCount() === 1, 'a layout is a snapshot');
    $layout = $pd->layout();
    check($layout->getPageCount() === 2 && $layout->getPages($doc->getElementById('c')) === [2]);
    check(Pdf::open($pd->writePDF())->getNumberOfPages() === 2);
});

test('DOM: before readHTML and with the v40 engine', function () {
    requires42();
    foreach ([new PD4ML(), new PD4ML(null, null, 'v40')] as $i => $pd) {
        if ($i === 1) {
            $pd->readHtmlString(HTML);
        }
        foreach (['getDocument', 'layout'] as $method) {
            try {
                $pd->$method();
                check(false, "$method: no exception");
            } catch (JavaException $e) {
                check($e->isInstanceOf('java.lang.IllegalStateException'), $e->javaClass);
            }
        }
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

test('default version: release from MINIMUM_VERSION on, else a snapshot of that line', function () {
    check(Installer::MINIMUM_VERSION === '4.2.0');
    // the repository today: 4.1.2 is the newest release, 4.2.0-SNAPSHOT the only 4.2 build
    $today = ['4.0.20fx3-jakarta', '4.0.23fx2', '4.0.23fx2-SNAPSHOT', '4.1.1', '4.1.1-SNAPSHOT', '4.0.24',
        '4.1.2', '4.2.0-SNAPSHOT', 'bogus'];
    check(Installer::defaultVersion($today) === '4.2.0-SNAPSHOT');
    check(Installer::selectVersion($today) === '4.1.2', 'the newest release, as before');
    check(Installer::selectVersion($today, 4, true) === '4.2.0-SNAPSHOT', 'snapshots: the newest build');
    // a later 4.2.x snapshot is taken, a later line's is not
    check(Installer::defaultVersion([...$today, '4.2.1-SNAPSHOT', '4.3.0-SNAPSHOT', '5.0.0-SNAPSHOT']) === '4.2.1-SNAPSHOT');
    // once released, the release wins over any snapshot; later releases follow
    check(Installer::defaultVersion([...$today, '4.2.0', '4.2.1-SNAPSHOT', '4.3.0-SNAPSHOT']) === '4.2.0');
    check(Installer::defaultVersion([...$today, '4.2.0', '4.2.0fx1', '4.3.0', '4.3.1-SNAPSHOT']) === '4.3.0');
    check(Installer::defaultVersion([...$today, '4.2.0fx1a1']) === '4.2.0fx1a1', 'an fx pre-release counts');
    check(Installer::defaultVersion(['4.1.2', '5.0.0']) === '4.1.2', 'never a 5.x');
    // jakarta: the same rule over the -jakarta variants first; 4.2 has none (its builds hold the
    // Jakarta tag library), so then the plain 4.2 build
    check(Installer::defaultVersion([...$today, '4.2.0-jakarta-SNAPSHOT'], true) === '4.2.0-jakarta-SNAPSHOT');
    check(Installer::defaultVersion([...$today, '4.2.0-jakarta', '4.2.0-jakarta-SNAPSHOT', '4.2.1'], true) === '4.2.0-jakarta');
    check(Installer::defaultVersion($today, true) === '4.2.0-SNAPSHOT', 'jakarta: the plain 4.2 build');
    // no 4.2 release or 4.2.x snapshot at all: the newest 4.* release of the variant, as before
    check(Installer::defaultVersion(['4.1.2', '4.1.3-SNAPSHOT', '4.3.0-SNAPSHOT']) === '4.1.2');
    check(Installer::defaultVersion(['4.0.20fx3-jakarta', '4.1.2'], true) === '4.0.20fx3-jakarta');
});

test('install from a repository (offline)', function () {
    // a local Maven repository: its maven-metadata.xml lists the versions as pd4ml.com/maven2 does
    $repo = tmpdir();
    $group = "$repo/com/pd4ml/pd4ml";
    $publish = function (string $path, string $data) use ($group) {
        @mkdir(dirname("$group/$path"), 0777, true);
        file_put_contents("$group/$path", $data);
        file_put_contents("$group/$path.sha1", sha1($data));
    };
    $versions = ['4.0.20fx3-jakarta', '4.1.1', '4.1.2', '4.2.0-SNAPSHOT'];
    mkdir($group, 0777, true);
    file_put_contents("$group/maven-metadata.xml", '<?xml version="1.0" encoding="UTF-8"?><metadata>'
        . '<groupId>com.pd4ml</groupId><artifactId>pd4ml</artifactId><versioning><latest>4.2.0-SNAPSHOT</latest>'
        . '<release>4.1.2</release><versions><version>' . implode('</version><version>', $versions)
        . '</version></versions></versioning></metadata>');
    $publish('4.2.0-SNAPSHOT/maven-metadata.xml', '<metadata><versioning><snapshot><timestamp>20261106.120000</timestamp>'
        . '<buildNumber>7</buildNumber></snapshot><snapshotVersions><snapshotVersion><extension>jar</extension>'
        . '<value>4.2.0-20261106.120000-7</value></snapshotVersion></snapshotVersions></versioning></metadata>');
    $publish('4.2.0-SNAPSHOT/pd4ml-4.2.0-20261106.120000-7.jar', 'snapshot jar');
    $publish('4.1.2/pd4ml-4.1.2.jar', 'release jar');
    $publish('4.0.20fx3-jakarta/pd4ml-4.0.20fx3-jakarta.jar', 'jakarta jar');
    check(Installer::availableVersions($repo) === $versions);
    $options = ['repository' => $repo, 'home' => tmpdir(), 'quiet' => true, 'bouncycastle' => false];
    $jar = Installer::install('latest', $options);
    check(basename($jar) === 'pd4ml-4.2.0-20261106.120000-7.jar' && file_get_contents($jar) === 'snapshot jar', $jar);
    check(Installer::installedVersion($options['home']) === '4.2.0-SNAPSHOT');
    check(basename(Installer::install('4.1.2', $options)) === 'pd4ml-4.1.2.jar', 'pinned');
    check(Installer::installedVersion($options['home']) === '4.1.2');
    check(basename(Installer::install('latest', ['snapshots' => true] + $options)) === 'pd4ml-4.2.0-20261106.120000-7.jar');
    check(basename(Installer::install('4.0.20fx3-jakarta', $options)) === 'pd4ml-4.0.20fx3-jakarta.jar');
    check(Installer::installedVersion($options['home']) === '4.0.20fx3-jakarta');
    // jakarta: no 4.2 -jakarta build; the 4.2 build holds the Jakarta tag library
    check(basename(Installer::install('latest', ['jakarta' => true] + $options)) === 'pd4ml-4.2.0-20261106.120000-7.jar');
    check(Installer::installedVersion($options['home']) === '4.2.0-SNAPSHOT');
});

if (getenv('PD4ML_NETWORK_TESTS') === '1') {
    test('install and convert (network)', function () {
        $home = tmpdir();
        $script = 'require ' . var_export(dirname(__DIR__) . '/src/autoload.php', true) . ';'
            . '$jar = Pd4ml\Installer::install("latest", ["quiet" => true]);'
            . '$v = Pd4ml\Installer::installedVersion();'
            . 'if (Pd4ml\Installer::versionKey($v) < Pd4ml\Installer::versionKey("4.2.0-SNAPSHOT")) exit(4);'
            . 'if (count(Pd4ml\Installer::installedClasspath()) !== 4) exit(3);'
            . '$pd = @new Pd4ml\PD4ML(); $pd->setPermissions("s", Pd4ml\Java::Constants()->AllowPrint);'
            . '$pd->readHtmlString("<p>x</p>"); echo str_contains($pd->writePDF(), "/Encrypt") ? "ok" : "no";';
        $env = ['PD4ML_HOME' => $home, 'JAVA_HOME' => (string) getenv('JAVA_HOME'),
            'PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot'),
            // a temporary directory for Java: without one PD4ML 4.2 reports its built-in fonts missing
            'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir(), 'TMPDIR' => sys_get_temp_dir()];
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
$skipped = 0;
foreach ($tests as $name => $body) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $body();
        $passed++;
        echo "  ok    $name\n";
    } catch (SkipTest $e) {
        $skipped++;
        echo "  skip  $name: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  $name: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
Bridge::shutdown();
echo "\n$passed passed, $failed failed" . ($skipped ? ", $skipped skipped" : '') . "\n";
exit($failed ? 1 : 0);
