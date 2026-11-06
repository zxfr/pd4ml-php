# PD4ML for PHP — User Guide

`pd4ml-php` brings [PD4ML](https://pd4ml.com) to PHP: it converts HTML and CSS into **PDF**,
**PDF/A**, **PDF/UA**, **ZUGFeRD/Factur-X** invoices, **DOCX**, **RTF** and **page images**, and
gives PHP applications the complete public PD4ML API.

PD4ML is a Java library. `pd4ml-php` runs it in a small Java *bridge* process that PHP talks to
over a pipe (or a local TCP port): every PD4ML class and method can be called from PHP as if it
were a PHP object, and the module adds PHP-style I/O on top — file paths, strings and streams
instead of Java streams.

The PD4ML manual, which this guide refers to throughout, is at <https://pd4ml.com/docs/>.
The generated reference of every class and method is [api.md](api.md).

**Contents**

1. [Prerequisites](#1-prerequisites)
2. [Installation](#2-installation)
3. [Quick start](#3-quick-start)
4. [How it works](#4-how-it-works)
5. [Use cases](#5-use-cases)
6. [Tips and hints](#6-tips-and-hints)
7. [Troubleshooting](#7-troubleshooting)
8. [API reference](#8-api-reference)
9. [Further reading](#9-further-reading)

---

## 1. Prerequisites

| Requirement | Notes |
|---|---|
| **PHP 8.1 or later** | CLI, FPM or any SAPI that may start processes (`proc_open`) or open local sockets. Extensions: `json`, `mbstring`, `simplexml`; `openssl` for downloading PD4ML. |
| **Composer** | To install the module from [Packagist](https://packagist.org/packages/pd4ml/pd4ml-php) (<https://getcomposer.org>); optional, see 2.1. |
| **Java 8 or later** | A JDK or JRE; an LTS release (17, 21) is recommended. Found through `PD4ML_JAVA`, `JAVA_HOME`, or `java` on the `PATH`. |
| **PD4ML** | Downloaded by `vendor/bin/pd4ml install` (see below), or any PD4ML build you point the module at. |
| **Network access** | Only for the download; conversions themselves need none, unless the HTML references remote resources. |
| **A PD4ML license** | Optional for evaluation: without one, PD4ML runs in evaluation mode and watermarks its output. See [Licensing](https://pd4ml.com/docs/#3.-licensing) and [5.18](#518-licensing). |

Check with `php -v`, `php -m` and `java -version`. On a server without Java, install a JRE
package (for example `apt install openjdk-21-jre-headless`) and, if it is not on the `PATH`,
set `JAVA_HOME` or `PD4ML_JAVA`.

## 2. Installation

### 2.1 Add the module

The module is published on [Packagist](https://packagist.org/packages/pd4ml/pd4ml-php),
the main Composer repository, as `pd4ml/pd4ml-php`. In your project's directory:

```
composer require pd4ml/pd4ml-php
```

Composer downloads the module into `vendor/`, adds it to `composer.json` (as `^1.1`, so later
1.x releases are accepted) and installs its command-line tool as `vendor/bin/pd4ml`
(`vendor\bin\pd4ml` on Windows). Load the module with Composer's autoloader:

<!-- no-test -->
```php
require __DIR__ . '/vendor/autoload.php';
```

`composer update pd4ml/pd4ml-php` updates it later. The source, issues and release tags are at
<https://github.com/zxfr/pd4ml-php>.

**Without Composer**, clone or download the source anywhere, include its own autoloader
(`require '/path/to/pd4ml-php/src/autoload.php';`) and run the tool as `php bin/pd4ml` instead
of `vendor/bin/pd4ml` in the commands below.

### 2.2 Install PD4ML

```
vendor/bin/pd4ml install
```

This downloads PD4ML 4.2 from the PD4ML Maven repository
(<https://pd4ml.com/maven2>), together with the Bouncy Castle jars PD4ML needs for encryption
and signing (from Maven Central), checks every file against its published SHA-1 checksum and
stores them in a per-user cache directory (`%LOCALAPPDATA%\pd4ml-php` on Windows,
`~/.cache/pd4ml-php` elsewhere). The module then uses that build by itself; run the command
again to update.

**Which build**: the newest PD4ML release from 4.2.0 on or, while there is none, the newest
4.2.x development snapshot (`4.2.0-SNAPSHOT` until 4.2.0 is released). Module 1.1 wraps the
PD4ML 4.2 API — `readEML()`/`readMHT()`, `addAllowedResourceLocation()`, XMP `addMetadata()`,
the DOM API — which builds before 4.2 do not have, so the default is a snapshot until PD4ML
4.2.0 is out; from then on the same command installs the release, and later snapshots (4.3...)
are never picked by accident. Earlier builds still work with the rest of the module: pin one
with `--version`, e.g. `--version=4.1.2`.

| Option | |
|---|---|
| *(none)* | the newest release from 4.2.0 on, else the newest 4.2.x snapshot |
| `--snapshots` | the most recent 4.x build, development snapshots included |
| `--version=4.1.2` | an exact version (`4.0.23fx2`, `4.2.0-SNAPSHOT`...) |
| `--jakarta` | for Jakarta EE / Servlet 6 environments: a `-jakarta` variant chosen by the same rule if there is one; PD4ML 4.2 publishes none - its builds contain the Jakarta tag library - so this installs the same 4.2 build (an older `-jakarta` build with `--version`, e.g. `4.0.20fx3-jakarta`) |
| `--home=DIR` | install into `DIR` (default: `PD4ML_HOME`, else the cache directory) |
| `--force` | download again |
| `--no-bouncycastle` | skip the Bouncy Castle jars (encryption and signing will not work) |
| `vendor/bin/pd4ml list` | list the available versions |

The same from PHP, for a script's initialisation:

<!-- no-test -->
```php
use Pd4ml\Installer;

Installer::install();                       // downloads only when that build is not there yet
                                            // Installer::install('4.1.2') pins a version
echo Installer::installedVersion(), PHP_EOL;
```

Check the installation: `vendor/bin/pd4ml version`.

> **Web servers**: the user the web server runs as must see the installed build — install it
> as that user, or install into a shared directory with `--home=` and set `PD4ML_HOME` for the
> web server too.

### 2.3 Other ways to provide PD4ML

The module looks for PD4ML in this order:

1. `Bridge::configure(['classpath' => [...]])`, before the first conversion;
2. the `PD4ML_CLASSPATH` environment variable — jars and class directories separated by `;`
   (Windows) or `:` (elsewhere), `dir/*` for all jars of a directory;
3. the `PD4ML_CLASSPATH_FILE` environment variable — a file with one entry per line;
4. the build `vendor/bin/pd4ml install` downloaded.

Typical reasons to use them:

- **Offline / air-gapped servers**: `vendor/bin/pd4ml install --home=./pd4ml-libs` on a machine
  with network access, copy the directory, set `PD4ML_HOME` on the target.
- **A licensed or specific jar**: `PD4ML_CLASSPATH=/opt/pd4ml/pd4ml.jar:/opt/pd4ml/lib/*`.
- **A PD4ML development build** (the v42 layout engine, see [4.3](#43-layout-engines)):
  a classpath file listing the build's classes and its Maven dependencies, in
  `PD4ML_CLASSPATH_FILE`.

## 3. Quick start

```php
use Pd4ml\PD4ML;
use Pd4ml\Java;

$pd = new PD4ML();
$pd->setPageSize(Java::PageSize()->A4);
$pd->readHtmlString('<h1>Hello, PDF</h1><p>Generated with <b>PD4ML</b>.</p>');
$pd->writePDF('hello.pdf');
```

Or in one call:

```php
use Pd4ml\Pdf;
use Pd4ml\Bytes;

$pdf = Pdf::htmlToPdf(new Bytes('<h1>Invoice 42</h1>'));
assert(str_starts_with($pdf, '%PDF'));
```

## 4. How it works

### 4.1 The two-phase model

Every conversion is **read once, written any number of times**: `readHTML` parses and lays out
the document; each `writePDF`, `writeDOCX`, `writeRTF` or `renderAsImages` then produces one
output from that same layout. Settings (page size, headers, fonts...) go before `readHTML`.
See [the two-phase model](https://pd4ml.com/docs/#6.1-the-two-phase-model).

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHtmlString('<h1>Quarterly report</h1>');
$pd->writePDF('report.pdf');
$pd->writeDOCX('report.docx');
$thumbnails = $pd->renderPng();          // PNG images, one string per page
```

### 4.2 The bridge

- **Per process (default).** The first use of PD4ML starts a Java bridge process for the PHP
  process; it serves every conversion of that PHP process and ends with it. A CLI script, a
  queue worker or a long-running PHP server starts Java once (roughly a second); afterwards a
  conversion of an ordinary document takes milliseconds.
- **Shared server (web servers).** Under PHP-FPM, mod_php or CGI, each request is a fresh
  start — run one bridge server for all of them instead:

  ```
  vendor/bin/pd4ml serve --port=8723          # keep it running (systemd, supervisor, a Windows service)
  ```

  and set `PD4ML_BRIDGE=tcp://127.0.0.1:8723` for the web server (or call
  `Bridge::configure(['server' => 'tcp://127.0.0.1:8723'])`). The server listens on the loopback
  interface only; each connection has its own objects.
- JVM options (memory, system properties): `PD4ML_JVM_OPTIONS="-Xmx2g"`, or
  `Bridge::configure(['jvmOptions' => ['-Xmx2g']])` before the first use. Java runs headless.
- The bridge's own console output is discarded; `PD4ML_BRIDGE_LOG=/path/to/file` keeps it.

### 4.3 Layout engines

PD4ML 4.2 introduces a second HTML layout engine, **v42**, written for close agreement with
current browsers (flexbox, grid, modern CSS). `new PD4ML()` uses it by default:

```php
use Pd4ml\PD4ML;
use Pd4ml\Runtime;

if (Runtime::hasLayoutEngines()) {
    $pd = new PD4ML();                       // v42
    $legacy = new PD4ML(null, null, 'v40');  // the previous engine
    echo $pd->engine, ' ', $legacy->engine, PHP_EOL;
}
```

Builds before 4.2 — including the current public release, 4.1.x — have one engine only. With
such a build the module uses it and raises a notice (`E_USER_NOTICE`) once;
`Runtime::hasLayoutEngines()` returns false, and asking for an engine explicitly throws.

### 4.4 PHP and Java values

- Java methods keep their **Java names** (`setPageSize`, `readHTML`...) and take PHP values:
  `string`, `int`, `float`, `bool`, `null`, arrays (lists for Java arrays/lists, associative
  arrays for `Map`s).
- A Java object comes back as a `JavaObject`: call its methods, read its public fields
  (`$size->width`), cast it to string (`toString()`). Java `null` is `null`.
- Java classes: `Java::PageSize()`, `Java::Constants()`, `Java::PdfSpec()`... (see
  `Pd4ml\Java`), any other one with `Java::cls('java.util.Locale')`. Static fields and methods
  are read on them with `->` (`Java::PageSize()->A4`), constructors called with `->new(...)`.
- **Binary data**: PHP strings go to Java as text. Wrap binary content — a PDF, an HTML file's
  raw bytes — in `new Bytes($data)` (strings that are not valid UTF-8 are sent as bytes
  anyway). A Java `byte[]` comes back as a PHP string.
- **Floats**: write `10.0` rather than `10` where a Java method has both `int` and `float`
  overloads and you want the latter.

### 4.5 Exceptions

Java exceptions are thrown as `Pd4ml\JavaException`, which knows the Java class and its
superclasses:

```php
use Pd4ml\JavaException;
use Pd4ml\PD4ML;

try {
    $pd = new PD4ML();
    $pd->readHTML('file:///no/such/document.html');
    $pd->writePDF();
} catch (JavaException $e) {
    if ($e->isInstanceOf('java.io.IOException')) {
        echo 'cannot read the document: ', $e->getJavaMessage(), PHP_EOL;
    }
}
```

`$e->javaClass`, `$e->causes` and `$e->javaTrace` hold the details. Problems with the bridge
itself (no Java, no PD4ML build) are `Pd4ml\BridgeException`.

---

## 5. Use cases

The snippets below are complete and runnable; files they read (`report.html`...) are assumed to
exist in the working directory, and Composer's autoloader (`vendor/autoload.php`) to be loaded.

### 5.1 HTML string to PDF

The common case for web applications: render HTML with your template engine (Twig, Blade,
plain PHP), convert it in memory.

```php
use Pd4ml\PD4ML;

$html = '<html><body><h1>Order 1001</h1><p>Thank you for your order.</p></body></html>';

$pd = new PD4ML();
$pd->readHtmlString($html);
$pdf = $pd->writePDF();                 // the PDF as a string
```

`writePDF`, `writeDOCX` and `writeRTF` return the document without a target, write to a file
path, or write into an open stream (`fopen(...)`, `php://output`, `php://memory`).

### 5.2 Files and URLs

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHTML('report.html');           // also: 'https://example.com/report'
$pd->writePDF('report.pdf');
```

**Relative links** (`<img src="logo.png">`, `<link href="style.css">`) resolve against the
document's own location when it is read from a path or URL. When the HTML comes from a string,
`Bytes` or a stream, pass the base location — otherwise images and stylesheets silently go
missing (see [reading the source](https://pd4ml.com/docs/#6.2-reading-the-source)):

```php
use Pd4ml\PD4ML;

$html = '<link rel="stylesheet" href="style.css"><p class="note">Styled</p>';
$pd = new PD4ML();
$pd->readHtmlString($html, '.');        // a directory, file path or URL
$pd->writePDF('styled.pdf');
```

For a document in a known non-UTF-8 encoding: `$pd->readHTML(new Bytes($data), $base, 'windows-1251')`.

### 5.3 Page size, orientation, margins and scaling

```php
use Pd4ml\Java;
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->setPageSize(Java::PageSize()->LETTER->rotate());                             // landscape
$pd->setPageMargins(Java::PageMargins()->new(15, 20, 15, 20, Java::Units()->MM)); // left, top, right, bottom
$pd->setHtmlWidth(1000);                  // the virtual browser width the page is laid out in
$pd->readHtmlString("<table width='100%'><tr><td>wide content</td></tr></table>");
$pd->writePDF('landscape.pdf');
```

The layout is scaled to the page: `scale = printable width / html width`. A smaller HTML width
makes everything larger. `adjustHtmlWidth(true)` uses the document's own natural width;
`fitPageVertically(Java::Constants()->CENTER_ALIGN)` shrinks the whole document onto a single
page. Page size and margins accept a page *scope* (`'1'`, `'2+'`, `'odd'`...) as a second
argument. More in [page layout](https://pd4ml.com/docs/#7.-page-layout-size-margins-and-scaling).

Custom sizes: `Java::PageSize()->new(100.0, 150.0, Java::Units()->MM)`; named sizes:
`Java::PageSize()->getInstance('A5')`.

### 5.4 Headers, footers and page numbers

Headers and footers are HTML fragments; `$[page]`, `$[total]` and `$[title]` are filled in per
page, and `setDynamicData` adds placeholders of your own (note the single quotes, so PHP does
not interpolate `$[...]`):

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->setDynamicData(['customer' => 'Acme Corp.', 'invoice' => 'INV-2026-0031']);
$pd->setPageHeader('<div style="font-size:9pt"><b>$[title]</b></div>', 30, '1+');
$pd->setPageFooter(
    '<div style="font-size:8pt; text-align:right">'
    . 'Invoice $[invoice] &mdash; $[customer] &mdash; page $[page] of $[total]</div>',
    24, '1+');
$pd->readHtmlString('<title>Invoice</title><h1>Invoice</h1>' . str_repeat('<p>Line item</p>', 80));
$pd->writePDF('invoice.pdf');
```

The second argument is the area height in pixels, the third the page scope: `'1'` (first page),
`'2+'` (all but the first), `'odd'`, `'even'`, `'3-7'`... Headers and footers can also be
written inline in the HTML with PD4ML's extension tags. See
[headers, footers, watermarks and backgrounds](https://pd4ml.com/docs/#8.-headers-footers-watermarks-and-backgrounds)
and the [usage examples](https://pd4ml.com/docs/usage-examples.html#set-page-header-and-footer).

### 5.5 Watermarks and page backgrounds

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->setWatermark('<span style="font-size:72pt; color:#c00">DRAFT</span>',
    120.0, 350.0,    // left, top (px)
    0.15,            // opacity
    45.0,            // rotation, degrees
    1.0,             // scale
    true, true,      // visible on screen, in print
    '1+');           // pages
$pd->setPageBackground('<div style="background:#f7f7f2; height:100%"></div>', '1+');
$pd->readHtmlString('<h1>Proposal</h1>');
$pd->writePDF('draft.pdf');
```

### 5.6 Fonts

PD4ML covers Unicode broadly with its built-in fonts. For a specific typeface (a corporate font,
CJK coverage), give it a font directory:

<!-- no-test -->
```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->useTTF('/opt/fonts', true);      // a directory of TTF/OTF fonts (indexed: see below)
$pd->embedTTFs(true, false);          // embed only the glyphs used
$pd->applyKerning(true);
$pd->enableLigatures(true);
```

Index a font directory once ahead of time — PD4ML then reads the index
(`pd4fonts.properties`) instead of scanning all fonts on every conversion
([font indexing](https://pd4ml.com/docs/#4.3-font-indexing)):

```
java -jar <pd4ml jar> -configure.fonts /opt/fonts
```

(`php -r "require 'vendor/autoload.php'; echo Pd4ml\Installer::installedJar();"` prints the jar's
path.) CSS `@font-face` web fonts in the document work as well.

### 5.7 Bookmarks, links and tables of contents

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->generateBookmarksFromHeadings(true);    // one PDF bookmark per <h1>..<h6>
$pd->enableHyperlinks(true);                 // <a href> become clickable links (the default)
$pd->readHtmlString('<h1>Introduction</h1><p>...</p><h1 style="page-break-before:always">Details</h1>');
$pd->writePDF('bookmarked.pdf');
```

A table of contents with page numbers can be generated from the headings with PD4ML's
`<pd4ml:toc>` tag: see the [usage examples](https://pd4ml.com/docs/usage-examples.html#add-toc).
Explicit page breaks: CSS `page-break-before` / `break-before`, or `<pd4ml:page.break>`.

### 5.8 PDF/A and PDF/UA

```php
use Pd4ml\Java;
use Pd4ml\PD4ML;

$spec = Java::PdfSpec();
$pd = new PD4ML();
$pd->readHtmlString("<html lang='en'><title>Annual report</title><h1>Annual report</h1>");
$pd->writePDF('archive.pdf', $spec->PDFA_3B);                          // archival
$pd->writePDF('accessible.pdf', $spec->PDFUA_1);                       // accessible
$pd->writePDF('both.pdf', $spec->PDF_1_7->combine($spec->PDFA_2A));

$status = $pd->getLastRenderInfo(Java::Constants()->PD4ML_PDFA_STATUS); // after writing
```

The spec can also be given as a keyword string (`'pdf/a-3b'`; `Java::PdfSpec()->listKeywords()`
lists them); unknown keywords throw `InvalidArgumentException`. PDF/A embeds all fonts; PDF/UA
needs well-structured HTML (real headings, `alt` texts, `lang`, table headers) — combine with
`$pd->setErrorPolicy(Java::ErrorPolicy()->PEDANTIC)` to be told when the source does not satisfy
the standard. Details: [PDF/A, PDF/UA and e-invoicing](https://pd4ml.com/docs/#11.2-pdfa-pdfua-and-e-invoicing-formats).

### 5.9 ZUGFeRD / Factur-X e-invoices

`PdfSpec->ZUGFeRD` / `PdfSpec->FacturX` select the PDF/A-3 container the standards require; the
invoice XML is attached from the HTML with PD4ML's attachment tag. The complete recipe, with
the required attachment names, is on the
[ZUGFeRD and Factur-X page](https://pd4ml.com/docs/features/zugferd-and-factur-x-pdf-invoices.html).

<!-- no-test -->
```php
use Pd4ml\Java;
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHTML('invoice.html');        // contains the <pd4ml:attachment> of factur-x.xml
$pd->writePDF('invoice.pdf', Java::PdfSpec()->FacturX);
```

### 5.10 Passwords and permissions

```php
use Pd4ml\Java;
use Pd4ml\PD4ML;

$C = Java::Constants();
$pd = new PD4ML();
$pd->setPermissions('owner-secret', $C->AllowPrint | $C->AllowCopy, $C->AES_256BIT);
$pd->readHtmlString('<p>Confidential</p>');
$pd->writePDF('protected.pdf');
```

Combine the `Allow*` flags with `|`, or start from `DefaultPermissions` and remove flags with
`^`. Needs the Bouncy Castle jars (installed by `vendor/bin/pd4ml install`). See
[permissions and encryption](https://pd4ml.com/docs/#11.1-permissions-and-encryption).

### 5.11 Word (DOCX, RTF) and images

```php
use Pd4ml\Java;
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHtmlString('<h1>Letter</h1><p>Dear customer, ...</p>');
$pd->writeDOCX('letter.docx');
$pd->writeRTF('letter.rtf');
$pages = $pd->renderPng();                                    // PNG per page
$tiffs = $pd->renderAsImages(Java::Constants()->TIFF);        // one TIFF per page
$pd->renderAsImages('.', 'page', Java::Constants()->PNG24);   // page files in a directory
```

Image formats: `PNG8`, `PNG24`, `TIFF`, `TIFF_COMPRESSED` (`Constants`); the multi-page TIFF
formats are not supported by the v42 engine yet — use the v40 engine for them. With the v42
engine, DOCX/RTF output is *editable*: positioned content becomes text boxes and form fields,
not pictures.

### 5.12 Interactive forms and multi-column layout

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->generateForms(true, null);         // <input>/<select>/<textarea> become PDF form fields
$pd->readHtmlString("<form>Name: <input name='name' size='30'></form>");
$pd->writePDF('form.pdf');

$news = new PD4ML();
$news->generateMulticolumn(2, 20, true); // two columns, 20 mm apart
$news->readHtmlString('<h1>Newsletter</h1>' . str_repeat('<p>Lorem ipsum dolor sit amet.</p>', 200));
$news->writePDF('newsletter.pdf');
```

See [forms](https://pd4ml.com/docs/#10.1-interactive-pdf-forms) and
[multi-column pages](https://pd4ml.com/docs/#10.2-multi-column-pages) (PD4ML 4.0.24 / 4.1.2
and later).

### 5.13 Adding CSS and HTML programmatically

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->addStyle('body { font-family: sans-serif } h1 { color: #036 }', true);   // true: after the document's styles
$pd->injectHtml('<div style="font-size:8pt">Generated by the reporting service</div>', false);  // before </body>
$pd->readHtmlString('<h1>Report</h1>');
$pd->writePDF('report-styled.pdf');
```

`addStyle` also takes the URL of a stylesheet (`$pd->addStyle(Runtime::toUrl('print.css'), true)`).
See [injecting HTML](https://pd4ml.com/docs/#10.3-injecting-html-and-custom-resource-providers)
and [adding styles](https://pd4ml.com/docs/usage-examples.html#add-style-programmatically).

### 5.14 Progress, diagnostics and metadata

```php
use Pd4ml\Java;
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->monitorProgressWith(function (int $messageId, int $percent, ?string $note, int $msec) {
    printf("%3d%% %s (%d ms)\n", $percent, $note ?? '', $msec);
});
$pd->setErrorPolicy(Java::ErrorPolicy()->NORMAL);   // NONE, RELAXED, NORMAL, PEDANTIC, STRICT
$pd->setLogLevel(1);                                // 0 (silent) .. 5
$pd->setAuthorName('Reporting service');
$pd->setDocumentTitle('Monthly statement');
$pd->addMetadata('StatementId', '2026-10', false);
$pd->addMetadata('http://example.com/ns/statement/1.0/', 'st', 'Account', 'DE-0042');  // an XMP property (4.2)
$pd->readHtmlString('<h1>Statement</h1>');
$pd->writePDF('statement.pdf');
$pages = (int) $pd->getLastRenderInfo(Java::Constants()->PD4ML_TOTAL_PAGES);
```

The callback runs in PHP while the conversion runs in Java; an exception it throws comes out of
the call that caused it. The v42 engine currently reports fewer progress stages than v40. More
in [extensibility and diagnostics](https://pd4ml.com/docs/#12.-extensibility-and-diagnostics).

### 5.15 Working with existing PDFs

```php
use Pd4ml\Bytes;
use Pd4ml\Pdf;

$cover = Pdf::htmlToPdf(new Bytes('<h1 style="margin-top:300px; text-align:center">Annual Report</h1>'));
$body = Pdf::htmlToPdf(new Bytes('<h2>Chapter 1</h2><p>...</p>'));

$doc = Pdf::open($cover);                 // a path, URL, PDF string, Bytes or stream (+ password)
$doc->append(Pdf::open($body));           // pages of another document after these
$doc->setTitle('Annual Report');
Pdf::write($doc, 'annual-report.pdf');

$stamp = Pdf::htmlToPdf(new Bytes('<p style="color:#c00; font-size:40pt">COPY</p>'));
$original = Pdf::open('annual-report.pdf');
$original->overlay(Pdf::open($stamp), 128);       // draw over every page, half opaque (0..255)
Pdf::write($original, 'annual-report-copy.pdf', 'secret');

echo Pdf::open('annual-report.pdf')->getNumberOfPages(), PHP_EOL;
```

`underlay` draws another document *under* the pages (a letterhead), `Pdf::merge($a, $b)`
appends two documents in one call, `deletePage`, `getPageSize`, `getMetadata`, `dumpOutlines`
inspect and edit a document. PD4ML's PDF toolkit (signing, optimisation, XFDF) is described in
[the PDF post-processing toolkit](https://pd4ml.com/docs/#15.-the-pdf-post-processing-toolkit)
and reachable through `Java::cls(...)`.

### 5.16 Web applications

With the bridge server running (`vendor/bin/pd4ml serve`, [4.2](#42-the-bridge)) and
`PD4ML_BRIDGE` set for the web server:

<!-- no-test -->
```php
// plain PHP
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHtmlString(render_invoice_html($number), __DIR__ . '/assets');
header('Content-Type: application/pdf');
header("Content-Disposition: inline; filename=invoice-$number.pdf");
$pd->writePDF(fopen('php://output', 'wb'));
```

<!-- no-test -->
```php
// Laravel
use Pd4ml\PD4ML;

Route::get('/invoice/{number}.pdf', function (int $number) {
    $pd = new PD4ML();
    $pd->readHtmlString(view('invoice', ['number' => $number])->render(), public_path());
    return response($pd->writePDF(), 200, ['Content-Type' => 'application/pdf']);
});
```

<!-- no-test -->
```php
// Symfony controller
use Pd4ml\PD4ML;
use Symfony\Component\HttpFoundation\Response;

public function invoice(int $number): Response
{
    $pd = new PD4ML();
    $pd->readHtmlString($this->renderView('invoice.html.twig', ['number' => $number]),
        $this->getParameter('kernel.project_dir') . '/public');
    return new Response($pd->writePDF(), 200, ['Content-Type' => 'application/pdf']);
}
```

### 5.17 Batch conversion

```php
use Pd4ml\PD4ML;

foreach (glob('*.html') as $source) {
    $pd = new PD4ML();                       // one converter per document
    $pd->readHTML($source);
    $pd->writePDF(preg_replace('/\.html$/', '.pdf', $source));
    echo "written $source", PHP_EOL;
}
```

One bridge serves the whole loop. To convert in parallel, run several worker processes (each
with its own bridge), or point them all at one bridge server.

### 5.18 Licensing

<!-- no-test -->
```php
use Pd4ml\PD4ML;
use Pd4ml\Runtime;

$pd = new PD4ML('YOUR-SERIAL-NUMBER');   // or a license file URL; or pd4ml.lic in the working dir / classpath
if ($pd->isDemoMode()) {
    echo 'unlicensed: output is watermarked', PHP_EOL;
}
echo 'PD4ML ', Runtime::version(), $pd->isPro() ? ' pro' : '', PHP_EOL;
```

Without a license PD4ML is fully functional but marks its output. See
[licensing](https://pd4ml.com/docs/#3.-licensing).

### 5.19 E-mail messages and saved web pages

PD4ML 4.2 reads e-mail messages (`.eml`) and web pages saved as a single file (`.mht`,
`.mhtml`) and writes them to any output format:

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readEML('message.eml');            // a path or URL, Bytes, or an open stream
$pd->writePDF('message.pdf');
echo $pd->getDocumentTitle(), PHP_EOL;  // the subject

$page = new PD4ML();
$page->readMHT('page.mht');             // a saved web page, with its images and styles
$page->writePDF('page.pdf');
```

A message becomes a block with From, Date, To, Cc, Subject and the list of attachments,
followed by its body (the HTML part, else the plain text) with the images it embeds; the
attachments are embedded in the PDF (not in PDF/A-1/-2, DOCX or RTF). `readHTML()` recognizes
such files too, by their ending or their first lines — `readEML()`/`readMHT()` say what the
input is, the way to go for mail from outside your control.

Of the resources a message or saved page refers to but does not contain, only `https://` ones
are loaded; `addAllowedResourceLocation()` (5.20) replaces that default. Details:
[converting e-mail and saved web pages](https://pd4ml.com/docs/features/converting-e-mail-and-saved-web-pages.html).

### 5.20 Untrusted HTML: restricting resource loading

HTML written by users, customers or another system can make the server load anything it
points to: local files, internal services (SSRF), slow hosts. `addAllowedResourceLocation()`
limits loading — style sheets, images, web fonts, attachments, a document read from a URL —
to the locations you trust:

```php
use Pd4ml\PD4ML;

$userHtml = '<p>Hello <img src="file:/etc/passwd"></p>';

$pd = new PD4ML();
$pd->addAllowedResourceLocation('https://cdn.example.com/assets/');
$pd->addAllowedResourceLocation([__DIR__ . '/templates/', 'https://*.example.com/img/']);
$pd->readHtmlString($userHtml, 'https://cdn.example.com/assets/');
$pd->writePDF('user-content.pdf');       // without the image
```

A resource is loaded only if its URL starts with one of the locations (case-insensitive); `*`
matches any characters except `/`; a local path stands for its `file:` URL; a path with `..` is
always rejected. Each call adds to the list; with none, resources load from anywhere. A rejected
resource is left out; with `setErrorPolicy(Java::ErrorPolicy()->PEDANTIC)` a rejected image
stops the conversion instead. End server locations with `/` (`https://cdn.example.com` would
also match `https://cdn.example.com.attacker.net/`). `data:` URIs and the `useTTF()` fonts are
not restricted. See
[restricting resource loading](https://pd4ml.com/docs/features/restricting-resource-loading-for-untrusted-html.html).

### 5.21 Modifying the document before conversion (DOM API)

With the v42 layout engine (the default), the document `readHTML()` has parsed can be read,
queried with CSS selectors and changed before the output; every later output is laid out from
the changed document. `getDocument()` returns a `Pd4ml\Dom\Document`; nodes are `Dom\Element`,
`Dom\Text`, `Dom\Comment`... objects with the methods of the Java classes, and lists are PHP
arrays:

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHtmlString('<h1 id="number">0000</h1><img src="data:,"><table><tbody id="items">'
    . '<tr><td>sample</td></tr></tbody></table><p class="draft-only">Draft</p>');
$doc = $pd->getDocument();

$doc->getElementById('number')->setTextContent('INV-2026-0042');
foreach ($doc->querySelectorAll('img:not([alt])') as $img) {   // an array of Dom\Element
    $img->setAttribute('alt', '');
}
$doc->getElementById('items')->setInnerHTML('<tr><td>Consulting</td><td>8 h</td></tr>');
foreach ($doc->querySelectorAll('.draft-only') as $e) {
    $e->remove();
}
$note = $doc->createElement('p');
$note->setTextContent('Prices include VAT.');
$doc->getBody()->appendChild($note);

$style = $doc->createElement('style');
$style->setTextContent('@page { size: A4 landscape; margin: 15mm }');
$doc->getHead()->appendChild($style);

$pd->writePDF('invoice.pdf');
file_put_contents('invoice-as-converted.html', $doc->toHtml());
```

- **Navigating**: `getParentNode()`, `getChildNodes()`, `getFirstChild()`, `getNextSibling()`...;
  on elements `getChildren()`, `getFirstElementChild()`, `getNextElementSibling()`...
- **Querying**: `getElementById()`, `querySelector()`/`querySelectorAll()` (PD4ML's CSS selectors,
  `:has()`, `:nth-child()`... included), `getElementsByTagName()`, `getElementsByClassName()`,
  `matches()`, `closest()`.
- **Elements**: `getAttribute()`/`setAttribute()`/`removeAttribute()`, `getAttributes()` (an
  associative array), `getClassList()`, `addClass()`/`removeClass()`/`toggleClass()`,
  `getInnerHTML()`/`setInnerHTML()`, `getOuterHTML()`/`setOuterHTML()`.
- **Changing the tree**: `createElement()`, `createTextNode()`, `createComment()`, `appendChild()`,
  `insertBefore()`, `replaceChild()`, `removeChild()`, `remove()`, `cloneNode()`.
- Two objects for the same node are `equals()`, not necessarily `===`. Errors (an invalid
  selector, inserting a node into itself) are `JavaException`s
  (`java.lang.IllegalArgumentException`).

Placeholders of `setDynamicData()` are already filled in when `readHTML()` returns, and the
title (`$[title]`) is taken from the document then: change it with `setDocumentTitle()`.
`getDocument()` and `layout()` throw a `JavaException` (`java.lang.IllegalStateException`)
before `readHTML()` and with the v40 engine.

> **Edition**: the DOM API (`getDocument()`, `layout()`) is part of the PD4ML **DMS and UA
> editions** ([editions and prices](https://pd4ml.com/products/)). Without a license it works
> with the evaluation watermark; with an STD or PRO license, the output of a document it was
> used on carries "PD4ML EVALUATION MODE" banners.

**Checking the layout.** `layout()` lays the document out into pages as `writePDF()` would,
without writing it, and returns a `Dom\Layout`: page count and sizes, each element's boxes
(`Dom\LayoutBox`: page, x, y, width, height in PDF points from the *top left* of the page), the
pages it is on, its computed style. A layout is a snapshot — after changing the document, call
`layout()` again:

```php
use Pd4ml\PD4ML;

$pd = new PD4ML();
$pd->readHtmlString('<h1>Report</h1>' . str_repeat('<p>Line</p>', 60)
    . '<div id="totals" style="height:200px">Totals</div>');
$doc = $pd->getDocument();
$totals = $doc->getElementById('totals');

$layout = $pd->layout();
echo $layout->getPageCount(), ' pages', PHP_EOL;
foreach ($layout->getBoxes($totals) as $box) {
    echo $box, PHP_EOL;                  // page 3: 28.35, 35.64, 538.30 x 148.15 pt
}
if (count($layout->getPages($totals)) > 1) {    // split over two pages: start it on a new one
    $totals->setAttribute('style', 'height:200px; page-break-before: always');
}
echo $layout->getComputedStyle($totals, 'font-size'), PHP_EOL;
$pd->writePDF('report.pdf');
```

`elementAt($page, $x, $y)` returns the element at a point, `getPageNumber($page)` the number
printed on a page. The page header/footer, footnotes and endnotes are not part of the layout.
The complete guide, with more examples:
[modifying the HTML document before conversion](https://pd4ml.com/docs/features/modifying-the-html-document-before-conversion.html).

---

## 6. Tips and hints

- **Keep the bridge warm.** Under PHP-FPM/mod_php use the bridge server; a per-request Java
  start costs about a second. CLI scripts and workers pay it once.
- **One converter per document.** `new PD4ML()` is cheap; reuse the process, not the object.
- **Set the base location for HTML from strings.** Missing images and unstyled output are almost
  always unresolved relative links ([details](https://pd4ml.com/docs/#6.2-reading-the-source)).
- **Settings before `readHTML`.** Page size, headers, fonts and styles apply to the layout
  `readHTML` makes; writes afterwards only choose the output format.
- **Write several formats from one read.** PDF, DOCX and page images from a single `readHTML`
  cost one layout.
- **Single quotes for placeholders.** `'$[page]'` — in double quotes PHP tries to interpolate.
- **Print CSS works.** `@page { size: A4 landscape; margin: 15mm }`, `@media print`
  and `page-break-*` / `break-*` are honoured — often simpler than API calls.
- **Control the scale with the HTML width.** If text is too small or a layout wraps
  differently than in the browser, set `setHtmlWidth` to the width the page was designed for.
- **Index fonts once** with `-configure.fonts` instead of pointing `useTTF` at a large
  unindexed directory on every conversion.
- **Restrict what documents can load** when converting untrusted HTML:
  `addAllowedResourceLocation()` ([5.20](#520-untrusted-html-restricting-resource-loading)), and
  `setParam(Java::Constants()->PD4ML_DISABLE_EXTERNAL_ATTACHMENTS, 'true')`.
- **IDE support**: `Pd4ml\PD4MLApi` declares every Java method of `PD4ML` (`@method`), so
  completion works on the converter; [api.md](api.md) lists the API of every class.
- **Choose the build for production**: pin it with `vendor/bin/pd4ml install --version=X` (or a
  `PD4ML_CLASSPATH` to a jar you keep) rather than following the latest release.

## 7. Troubleshooting

| Symptom | Cause and remedy |
|---|---|
| `BridgeException: PD4ML not found` | No build configured: run `vendor/bin/pd4ml install` (as the user PHP runs as), or set `PD4ML_CLASSPATH`. |
| `BridgeException: cannot start java` / "closed the connection" | Java is missing or failed: check `java -version`, set `JAVA_HOME` or `PD4ML_JAVA`; `PD4ML_BRIDGE_LOG` shows Java's output. `proc_open` must not be in `disable_functions`. |
| `BridgeException: cannot connect` | The bridge server is not running, or `PD4ML_BRIDGE` names another port. |
| Slow first conversion per request | No bridge server under a web server: see [4.2](#42-the-bridge). |
| `InstallException: the openssl PHP extension is needed` | Enable `extension=openssl` in `php.ini`. |
| Images or styles missing | Relative links without a base location, or resources the server cannot reach (proxies, authentication): see `setCookie`, `setHttpOptions`. |
| Output is watermarked | No license installed ([licensing](https://pd4ml.com/docs/#3.-licensing)). |
| Squares instead of characters | The fonts lack those glyphs: provide fonts with `useTTF` ([fonts](https://pd4ml.com/docs/#9.-fonts-bookmarks-and-hyperlinks)). |
| `NoClassDefFoundError: org/bouncycastle/...` with `setPermissions` | The Bouncy Castle jars are missing: install without `--no-bouncycastle`, or add them to the classpath. |
| Notice "has no v42 layout engine" | The PD4ML build predates 4.2: the conversion uses its own engine. Use a 4.2 build for v42. |
| `JavaException: ... NoSuchMethodException` | No Java method of that name takes those argument types — check [api.md](api.md); pass floats as `10.0`, binary data as `Bytes`. |

## 8. API reference

### PHP classes

| Class | |
|---|---|
| `PD4ML` | the converter: `new PD4ML(?string $license, ?int $htmlWidth, $engine)`; every Java method of `com.pd4ml.PD4ML` |
| `Java` | the PD4ML Java classes: `PageSize()`, `PageMargins()`, `Units()`, `Constants()`, `LayoutEngine()`, `ErrorPolicy()`, `PdfSpec()`, `PdfDocument()`, `Dimensions()`, `ProgressListener()`, `StatusMessage()`, `ResourceProvider()`, `CustomTag()`, `PD4ML()`; `cls($name)`; `proxy($interface, $callable)` |
| `Pdf` | `htmlToPdf($source, $target, $options)`, `open($source, $password)`, `write($doc, $target, $password, $permissions)`, `merge($a, $b, $target)` |
| `Runtime` | `version()`, `install()`, `configure()`, `hasLayoutEngines()`, `toUrl()`, `pdfSpec()` |
| `Installer` | `install($version, $options)`, `installedJar()`, `installedClasspath()`, `installedVersion()`, `availableVersions()`, `defaultVersion($versions)` (the default's rule; minimum `MINIMUM_VERSION`) |
| `Bridge` | `configure($config)`, `get()`, `shutdown()`, `classpath()` |
| `JavaObject`, `JavaClass` | a Java object / class: methods, fields, `->new()` |
| `Bytes`, `Output` | binary input / captured output stream |
| `JavaException`, `BridgeException`, `InstallException` | errors |
| `Dom\Document`, `Dom\Element`, `Dom\Text`, `Dom\Comment`, `Dom\DocumentType` (all `Dom\Node`) | the DOM API (5.21): the nodes of `getDocument()`, Java lists as PHP arrays |
| `Dom\Layout`, `Dom\LayoutBox` | `layout()`: pages, element boxes, computed styles |

### `PD4ML` methods with PHP additions

| Method | |
|---|---|
| `readHTML($source, ?string $baseUrl, ?string $encoding)` | source: path, URL string, `Bytes`, stream, Java URL/stream |
| `readHtmlString(string $html, ?string $baseUrl)` | the document as a string |
| `readEML($source)`, `readMHT($source)` | an e-mail message / a saved web page (4.2): path, URL string, `Bytes`, stream, Java URL/stream |
| `addAllowedResourceLocation(string\|array $locations)` | trusted locations (4.2): a string, a comma-separated list or an array; local paths as `file:` URLs |
| `getDocument(): Dom\Document`, `layout(): Dom\Layout` | the DOM API (4.2, v42 engine; DMS and UA editions) |
| `writePDF($target = null, $spec = null)` | to a path / stream, or returned; spec: `PdfSpec` or keyword |
| `writeDOCX($target = null)`, `writeRTF($target = null, bool $rtfWmf = false)` | as `writePDF` |
| `renderAsImages($format)` / `renderPng()` | pages as encoded images (strings) |
| `monitorProgressWith($listener)` | a PHP callable or a Java `ProgressListener` |
| `useHtmlRenderer($engine, ?int $htmlWidth, ?array $params)` | the layout engine by name |

All other methods are those of the Java class `com.pd4ml.PD4ML` ([api.md](api.md#pd4ml),
[Java API](https://pd4ml.com/docs/#6.-the-java-api)); static ones are called on the class
(`PD4ML::getVersion()`).

`ResourceProvider` and `CustomTag` are extended in **Java**; register yours with
`addCustomResourceProvider` / `addCustomTagHandler` and put its classes on the classpath.
Java *interfaces* (such as `ProgressListener`) can be implemented in PHP with `Java::proxy()`.
Examples: [resource loaders](https://github.com/zxfr/pd4ml-examples/blob/master/src/main/java/advanced/A005AddCustomResourceLoader.java),
[custom tags](https://github.com/zxfr/pd4ml-examples/blob/master/src/main/java/advanced/A008AddingCustomTagRenderer.java).

## 9. Further reading

- [PD4ML documentation](https://pd4ml.com/docs/) and the full [programmer's manual](https://pd4ml.com/docs/pd4ml-manual.html)
- [Usage examples](https://pd4ml.com/docs/usage-examples.html)
- [HTML5 element support matrix](https://pd4ml.com/docs/html5-element-support-matrix.html)
- [PD4ML for PHP compared with mPDF, Dompdf and TCPDF](https://pd4ml.com/docs/integrations/pd4ml-php/php-html-to-pdf-library-comparison.html)
- [Command-line tool reference](https://pd4ml.com/docs/pd4cmd-command-line-reference.html)
- [ZUGFeRD and Factur-X invoices](https://pd4ml.com/docs/features/zugferd-and-factur-x-pdf-invoices.html)
- [PDF post-processing toolkit](https://pd4ml.com/docs/#15.-the-pdf-post-processing-toolkit)
- [Java examples on GitHub](https://github.com/zxfr/pd4ml-examples)
