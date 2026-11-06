<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

/** A run of text (`com.pd4ml.dom.Text`). */
class Text extends Node
{
    public function getData(): string
    {
        return (string) $this->java->getData();
    }

    public function setData(string $data): void
    {
        $this->java->setData($data);
    }

    /** Whether the text is whitespace only. */
    public function isWhitespace(): bool
    {
        return (bool) $this->java->isWhitespace();
    }
}
