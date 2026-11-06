<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

/** The `<!DOCTYPE>` (`com.pd4ml.dom.DocumentType`). */
class DocumentType extends Node
{
    /** The name (`html`). */
    public function getName(): ?string
    {
        return $this->java->getName();
    }

    public function getPublicId(): ?string
    {
        return $this->java->getPublicId();
    }

    public function getSystemId(): ?string
    {
        return $this->java->getSystemId();
    }
}
