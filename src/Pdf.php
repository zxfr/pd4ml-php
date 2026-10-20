<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * One-call conversion and PDF documents.
 *
 *     $pdf = Pdf::htmlToPdf('report.html');               // a path, URL or Bytes
 *     $doc = Pdf::open($pdf);                             // a com.pd4ml.PdfDocument
 *     Pdf::write($doc, 'copy.pdf', password: 'secret');
 */
final class Pdf
{
    /**
     * Converts one document to PDF. `$source` as for {@see PD4ML::readHTML()}, `$target` as for
     * {@see PD4ML::writePDF()}. Options: `baseUrl`, `pageSize`, `pageMargins`, `spec`, `engine`,
     * `htmlWidth`.
     */
    public static function htmlToPdf(mixed $source, mixed $target = null, array $options = []): ?string
    {
        $pd = new PD4ML(null, $options['htmlWidth'] ?? null, $options['engine'] ?? null);
        if (isset($options['pageSize'])) {
            $pd->setPageSize($options['pageSize']);
        }
        if (isset($options['pageMargins'])) {
            $pd->setPageMargins($options['pageMargins']);
        }
        $pd->readHTML($source, $options['baseUrl'] ?? null);
        return $pd->writePDF($target, $options['spec'] ?? null);
    }

    /** A `com.pd4ml.PdfDocument` from a path, URL, PDF content (a string or {@see Bytes}) or a stream. */
    public static function open(mixed $source, ?string $password = null): JavaObject
    {
        $PdfDocument = Java::PdfDocument();
        if (is_string($source) && preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $source)) {
            return $PdfDocument->new(Java::cls('java.net.URL')->new($source), $password);
        }
        return $PdfDocument->new(self::bytesOf($source), $password);
    }

    /**
     * Writes a `PdfDocument` (after `append`, `overlay`...); with a password, encrypted with the
     * `Constants` permission flags (default `Constants::DefaultPermissions`).
     */
    public static function write(JavaObject $document, mixed $target = null, ?string $password = null,
                                 ?int $permissions = null): ?string
    {
        $out = [];
        if ($password === null) {
            Bridge::get()->call($document, 'write', [new Output()], $out);
        } else {
            $permissions ??= Java::Constants()->DefaultPermissions;
            Bridge::get()->call($document, 'write', [new Output(), $password, $permissions], $out);
        }
        return Runtime::deliver($out[0] ?? '', $target);
    }

    /** `PdfDocument::mergePDFs`: two PDF documents appended into one. */
    public static function merge(mixed $first, mixed $second, mixed $target = null): ?string
    {
        $out = [];
        Bridge::get()->callStatic('com.pd4ml.PdfDocument', 'mergePDFs',
            [self::bytesOf($first), self::bytesOf($second), new Output()], $out);
        return Runtime::deliver($out[0] ?? '', $target);
    }

    /** PDF content from a path, a stream, a string holding a PDF, or {@see Bytes}. */
    private static function bytesOf(mixed $source): Bytes
    {
        if ($source instanceof Bytes) {
            return $source;
        }
        if (is_resource($source)) {
            return new Bytes((string) stream_get_contents($source));
        }
        if (is_string($source) && !str_starts_with($source, '%PDF')) {
            $data = @file_get_contents($source);
            if ($data === false) {
                throw new \InvalidArgumentException("cannot read $source");
            }
            return new Bytes($data);
        }
        return new Bytes((string) $source);
    }
}
