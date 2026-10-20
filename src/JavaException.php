<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * A Java exception thrown by PD4ML (or any Java code called): its Java class, message, causes
 * and stack trace.
 *
 *     try { ... } catch (JavaException $e) {
 *         if ($e->isInstanceOf('java.io.IOException')) { ... }
 *     }
 */
class JavaException extends \RuntimeException
{
    /** @param string[] $hierarchy @param string[] $causes */
    public function __construct(
        string $message,
        public readonly string $javaClass,
        public readonly array $hierarchy = [],
        public readonly array $causes = [],
        public readonly string $javaTrace = '',
    ) {
        parent::__construct("$javaClass: $message");
    }

    /** @internal */
    public static function fromResponse(array $error): JavaException
    {
        return new JavaException(
            (string) ($error['message'] ?? ''),
            (string) ($error['class'] ?? 'java.lang.Throwable'),
            $error['hierarchy'] ?? [],
            $error['causes'] ?? [],
            (string) ($error['trace'] ?? ''),
        );
    }

    /** Whether the Java exception is of the class `$class` or a subclass (`java.io.IOException`). */
    public function isInstanceOf(string $class): bool
    {
        return in_array($class, $this->hierarchy, true) || $class === $this->javaClass;
    }

    /** The message without the class name. */
    public function getJavaMessage(): string
    {
        return substr($this->getMessage(), strlen($this->javaClass) + 2);
    }
}
