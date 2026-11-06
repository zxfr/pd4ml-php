<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

/** An HTML comment (`com.pd4ml.dom.Comment`). */
class Comment extends Node
{
    public function getData(): string
    {
        return (string) $this->java->getData();
    }

    public function setData(string $data): void
    {
        $this->java->setData($data);
    }
}
