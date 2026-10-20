<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * Binary data for Java: a `byte[]` or `InputStream` argument. PHP strings are passed to Java as
 * text; wrap binary content (an HTML file's raw bytes, a PDF) in `new Bytes($data)`. Strings
 * that are not valid UTF-8 are passed as bytes anyway.
 */
final class Bytes
{
    public function __construct(public readonly string $data)
    {
    }
}
