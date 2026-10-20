<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * An `OutputStream` argument whose content comes back to PHP: pass `new Output()` (or several,
 * `new Output(0)`, `new Output(1)`...) and read the bytes from the `$outputs` of
 * {@see Bridge::call()}. {@see PD4ML::writePDF()} and friends do this for you.
 */
final class Output
{
    public function __construct(public readonly int $index = 0)
    {
    }
}
