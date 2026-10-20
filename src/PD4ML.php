<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * An HTML to PDF/DOCX/RTF/image converter: `com.pd4ml.PD4ML`, with the v42 layout engine by
 * default.
 *
 * Every method of the Java class is available under its Java name (`setPageSize`, `addStyle`,
 * `useTTF`...). These also take PHP I/O: `readHTML` (a path, URL, {@see Bytes} or a stream
 * resource), `writePDF`, `writeDOCX`, `writeRTF` (a path, a stream resource, or the document
 * returned as a string), `renderAsImages`, and `monitorProgressWith` (a PHP callable).
 *
 *     $pd = new PD4ML();
 *     $pd->setPageSize(Java::PageSize()->A4);
 *     $pd->readHtmlString('<h1>Hello</h1>');
 *     $pdf = $pd->writePDF();
 *
 * @mixin PD4MLApi
 */
class PD4ML
{
    /** The layout engine a new converter uses unless told otherwise. */
    public const DEFAULT_ENGINE = 'v42';

    private static bool $warnedNoEngines = false;

    /** The Java object (`com.pd4ml.PD4ML`). */
    public readonly JavaObject $java;
    /** The layout engine chosen (`v42`, `v40`), or null for the build's own. */
    public ?string $engine = null;

    /**
     * @param string|null $license a license serial number or license file URL (null: `pd4ml.lic`
     *        on the classpath or in the working directory, else evaluation mode)
     * @param int|null $htmlWidth the width in pixels the HTML is laid out in
     * @param string|JavaObject|false|null $engine `'v42'`, `'v40'` or a `LayoutEngine`; null:
     *        the default (v42 where the build has layout engines, else the build's own); false:
     *        leave the Java default
     */
    public function __construct(?string $license = null, ?int $htmlWidth = null,
                                string|JavaObject|false|null $engine = null)
    {
        $class = Java::PD4ML();
        $this->java = match (true) {
            $license !== null && $htmlWidth !== null => $class->new($license, $htmlWidth),
            $license !== null => $class->new($license),
            $htmlWidth !== null => $class->new($htmlWidth),
            default => $class->new(),
        };
        if ($engine === null) {
            if (Runtime::hasLayoutEngines()) {
                $engine = self::DEFAULT_ENGINE;
            } else {
                $engine = false;
                if (!self::$warnedNoEngines) {
                    self::$warnedNoEngines = true;
                    trigger_error('PD4ML ' . Runtime::version() . ' has no ' . self::DEFAULT_ENGINE
                        . ' layout engine (from 4.2): its own engine is used', E_USER_NOTICE);
                }
            }
        }
        if ($engine !== false) {
            $this->useHtmlRenderer($engine);
        }
    }

    /** Java methods not overridden here. */
    public function __call(string $method, array $args): mixed
    {
        return $this->java->__call($method, $args);
    }

    /** Static Java methods: `PD4ML::getVersion()`. */
    public static function __callStatic(string $method, array $args): mixed
    {
        return Java::PD4ML()->__call($method, $args);
    }

    // ------------------------------------------------------------------------- layout engine

    /**
     * Chooses the layout engine: `'v42'`, `'v40'` or a `LayoutEngine`, optionally with the HTML
     * width and engine parameters.
     */
    public function useHtmlRenderer(string|JavaObject $engine = self::DEFAULT_ENGINE, ?int $htmlWidth = null,
                                    ?array $params = null): void
    {
        if (!Runtime::hasLayoutEngines()) {
            throw new \RuntimeException('PD4ML ' . Runtime::version()
                . ' has no layout engines to choose from (Constants.LayoutEngine is new in 4.2)');
        }
        $e = is_string($engine) ? Java::LayoutEngine()->valueOf($engine) : $engine;
        if ($params !== null) {
            $this->java->useHtmlRenderer($e, $htmlWidth ?? -1, array_map('strval', $params));
        } elseif ($htmlWidth !== null) {
            $this->java->useHtmlRenderer($e, $htmlWidth);
        } else {
            $this->java->useHtmlRenderer($e);
        }
        $this->engine = $e->enumName();
    }

    // --------------------------------------------------------------------------------- input

    /**
     * Reads the document to convert.
     *
     * @param string|Bytes|resource|JavaObject $source a URL (`https://...`, `file:...`), a local
     *        path, the document's bytes ({@see Bytes}), an open stream, or a Java `URL`/`InputStream`
     * @param string|null $baseUrl where relative links resolve (a URL or a local directory/file);
     *        for a path or URL source the default is its own location
     * @param string|null $encoding the document's encoding when it does not declare one
     */
    public function readHTML(mixed $source, ?string $baseUrl = null, ?string $encoding = null): void
    {
        if (is_string($source)) {
            $url = Runtime::toUrl($source);
            $encoding === null ? $this->java->readHTML($url) : $this->java->readHTML($url, $encoding);
            return;
        }
        if ($source instanceof JavaObject && $source->javaClassName() === 'java.net.URL') {
            $encoding === null ? $this->java->readHTML($source) : $this->java->readHTML($source, $encoding);
            return;
        }
        if (is_resource($source)) {
            $source = new Bytes((string) stream_get_contents($source));
        }
        $base = $baseUrl === null ? null : Runtime::toUrl($baseUrl);
        if ($baseUrl === null && $encoding === null) {
            $this->java->readHTML($source);
        } elseif ($encoding === null) {
            $this->java->readHTML($source, $base);
        } else {
            $this->java->readHTML($source, $base, $encoding);
        }
    }

    /** Reads the document from a string of HTML (UTF-8). */
    public function readHtmlString(string $html, ?string $baseUrl = null): void
    {
        $this->readHTML(new Bytes($html), $baseUrl, 'UTF-8');
    }

    // -------------------------------------------------------------------------------- output

    /**
     * Writes the PDF.
     *
     * @param string|resource|null $target a file path, an open stream; null: the PDF is returned
     * @param string|JavaObject|null $spec a `PdfSpec` (`Java::PdfSpec()->PDFA_2B`), one of its
     *        keywords (`'pdf/a-2b'`, see `PdfSpec::listKeywords()`), or `'PDF'`/`'PDFA'`/`'PDFUA'`
     * @return string|null the PDF when no target is given
     */
    public function writePDF(mixed $target = null, string|JavaObject|null $spec = null): ?string
    {
        $spec = Runtime::pdfSpec($spec);
        $out = [];
        $spec === null
            ? Bridge::get()->call($this->java, 'writePDF', [new Output()], $out)
            : Bridge::get()->call($this->java, 'writePDF', [new Output(), $spec], $out);
        return Runtime::deliver($out[0] ?? '', $target);
    }

    /** Writes a DOCX document (see {@see writePDF()} for `$target`). */
    public function writeDOCX(mixed $target = null): ?string
    {
        $out = [];
        Bridge::get()->call($this->java, 'writeDOCX', [new Output()], $out);
        return Runtime::deliver($out[0] ?? '', $target);
    }

    /** Writes an RTF document; `$rtfWmf`: images as WMF (see {@see writePDF()} for `$target`). */
    public function writeRTF(mixed $target = null, bool $rtfWmf = false): ?string
    {
        $out = [];
        Bridge::get()->call($this->java, 'writeRTF', [new Output(), $rtfWmf], $out);
        return Runtime::deliver($out[0] ?? '', $target);
    }

    /**
     * `renderAsImages($format)`: the pages as encoded images (`Constants::PNG24`...), an array of
     * strings; `renderAsImages($dir, $name, $format)`: image files written to a directory;
     * `renderAsImages()`: Java `BufferedImage` objects.
     */
    public function renderAsImages(mixed ...$args): mixed
    {
        if (count($args) === 3 && is_string($args[0])) {
            return $this->java->renderAsImages(Java::cls('java.io.File')->new($args[0]), $args[1], $args[2]);
        }
        return $this->java->renderAsImages(...$args);
    }

    /** The pages as 24-bit PNG images. */
    public function renderPng(): array
    {
        return $this->java->renderAsImages(Java::Constants()->PNG24);
    }

    // ------------------------------------------------------------------------------ progress

    /**
     * Reports conversion progress to a PHP callable `fn(int $messageId, int $progress, ?string
     * $note, int $msec)` or a Java `ProgressListener`.
     */
    public function monitorProgressWith(callable|JavaObject $listener): void
    {
        if (!$listener instanceof JavaObject) {
            $listener = Java::proxy('com.pd4ml.ProgressListener', $listener);
        }
        $this->java->monitorProgressWith($listener);
    }

}
