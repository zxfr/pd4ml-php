<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * A Java class: its static methods and fields (constants, enum values) and its constructors.
 *
 *     $PageSize = Java::PageSize();
 *     $a4 = $PageSize->A4;                         // static field
 *     $custom = $PageSize->new(100, 150);          // constructor
 *     $letter = $PageSize->getInstance('LETTER');  // static method
 */
final class JavaClass
{
    /** @var array<string, mixed> static final fields read so far */
    private array $constants = [];

    public function __construct(private readonly Bridge $bridge, public readonly string $name)
    {
    }

    /** A new instance: the public constructor matching the arguments. */
    public function new(mixed ...$args): JavaObject
    {
        return $this->bridge->create($this->name, $args);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->bridge->callStatic($this->name, $method, $args);
    }

    public function __get(string $field): mixed
    {
        // constants and enum values do not change: read once
        if (array_key_exists($field, $this->constants)) {
            return $this->constants[$field];
        }
        $value = $this->bridge->getField($this->name, $field);
        if (preg_match('/^[A-Z0-9_]+$|^v\d+$/', $field) || $value instanceof JavaObject && $value->enumName() !== null) {
            $this->constants[$field] = $value;
        }
        return $value;
    }

    public function __set(string $field, mixed $value): void
    {
        unset($this->constants[$field]);
        $this->bridge->setField($this->name, $field, $value);
    }
}
