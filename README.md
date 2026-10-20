# pd4ml-php

PD4ML for PHP: HTML and CSS to PDF, PDF/A, PDF/UA, DOCX, RTF and images, with the full public
PD4ML API (Java package `com.pd4ml`) available to PHP applications.

PD4ML runs in a small Java bridge process that PHP talks to; every public Java class, method,
constant and enum can be used from PHP, and the module adds PHP I/O (paths, strings, streams)
on top. New converters use the **v42 layout engine** by default.

```php
require 'vendor/autoload.php';            // or: require 'path/to/pd4ml-php/src/autoload.php';

use Pd4ml\PD4ML;
use Pd4ml\Java;

$pd = new PD4ML();                                        // v42 layout engine
$pd->setPageSize(Java::PageSize()->A4);
$pd->setPageMargins(Java::PageMargins()->new(15, 15, 15, 15, Java::Units()->MM));
$pd->readHTML('invoice.html');                            // a path, URL, Bytes or stream
$pd->writePDF('invoice.pdf', Java::PdfSpec()->PDFA_3B);   // a path or stream; no target: returned
```

**Documentation: [docs/guide.md](docs/guide.md)** — prerequisites, installation, use cases with
code, tips and troubleshooting; **[docs/api.md](docs/api.md)** — every class and method. The
PD4ML manual itself is at <https://pd4ml.com/docs/>.

## Requirements

- PHP 8.1+ with `json`, `mbstring`, `simplexml` (and `openssl` to download PD4ML)
- Java 8 or later (JDK or JRE): `JAVA_HOME`, `PD4ML_JAVA`, or `java` on the `PATH`

## Installation

```
composer require pd4ml/pd4ml-php        # or use the checkout with src/autoload.php
php bin/pd4ml install                   # the most recent PD4ML 4.* release (vendor/bin/pd4ml with Composer)
```

`pd4ml install` downloads PD4ML from <https://pd4ml.com/maven2> with the Bouncy Castle jars it
needs (from Maven Central), verifies them and stores them in the user's cache directory
(`PD4ML_HOME` to choose another); the module uses that build by itself. Options: `--snapshots`,
`--version=X`, `--jakarta`, `--home=DIR`, `--force`; `php bin/pd4ml list` shows the versions.

Alternatively point the module at a build with `PD4ML_CLASSPATH`, `PD4ML_CLASSPATH_FILE` or
`Bridge::configure(['classpath' => ...])`.

## Web servers

Under PHP-FPM or mod_php run one bridge server instead of a Java start per request:

```
php bin/pd4ml serve --port=8723
```

and set `PD4ML_BRIDGE=tcp://127.0.0.1:8723` for the web server.

## Command line

```
php bin/pd4ml convert page.html page.pdf [--spec=pdf/a-2b] [--engine=v40]   # also .docx, .rtf, .png
php bin/pd4ml version
```

## Development

| | |
|---|---|
| `php tests/run.php` | the tests (`PD4ML_NETWORK_TESTS=1`: also the download) |
| `php tests/docs.php` | runs the guide's code snippets |
| `php tools/build-bridge.php` | rebuilds `lib/pd4ml-bridge.jar` from `java/src` (needs a JDK) |
| `php tools/gen-stubs.php [classpath-file]` | regenerates `src/PD4MLApi.php` and `docs/api.md` from the PD4ML classes |

The tests and generators use the PD4ML build from `PD4ML_CLASSPATH`/`PD4ML_CLASSPATH_FILE`,
`./pd4ml.classpath` (one classpath entry per line), or the installed one.

## How it works

`lib/pd4ml-bridge.jar` (source in `java/src`, Java 8 bytecode) runs next to PD4ML and speaks a
line-based JSON protocol: PHP creates Java objects, calls methods (overloads resolved by
reflection) and reads fields by handle; bytes, streams, URLs and maps are converted at the
boundary; Java interfaces can be implemented by PHP callables (`Java::proxy()`), their calls
delivered to PHP while the conversion runs.
