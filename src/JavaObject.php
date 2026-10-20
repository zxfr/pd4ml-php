<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * A Java object living in the bridge: methods, public fields and `toString()` work as on a PHP
 * object. `$pageSize->rotate()`, `$margins->left`, `(string) $spec`.
 */
final class JavaObject implements \Stringable
{
    public function __construct(
        private readonly Bridge $bridge,
        private readonly int $handle,
        private readonly string $class,
        private readonly ?string $enumName = null,
    ) {
    }

    /** @internal */
    public function handle(): int
    {
        return $this->handle;
    }

    /** The Java class name (`com.pd4ml.PageSize`). */
    public function javaClassName(): string
    {
        return $this->class;
    }

    /** For an enum constant, its name (`v42`); else null. */
    public function enumName(): ?string
    {
        return $this->enumName;
    }

    /** Whether this object is an instance of the Java class or interface `$class`. */
    public function isInstanceOf(string $class): bool
    {
        return $this->bridge->isInstanceOf($this, $class);
    }

    /** Java `equals()`. */
    public function equals(mixed $other): bool
    {
        return (bool) $this->bridge->call($this, 'equals', [$other]);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->bridge->call($this, $method, $args);
    }

    public function __get(string $field): mixed
    {
        return $this->bridge->getField($this, $field);
    }

    public function __set(string $field, mixed $value): void
    {
        $this->bridge->setField($this, $field, $value);
    }

    public function __toString(): string
    {
        return (string) $this->bridge->call($this, 'toString');
    }

    public function __destruct()
    {
        $this->bridge->release($this->handle);
    }
}
