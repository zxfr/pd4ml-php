<?php

declare(strict_types=1);

namespace Pd4ml;

/** The bridge to Java cannot start or broke down (no Java, no PD4ML build, process gone). */
class BridgeException extends \RuntimeException
{
}
