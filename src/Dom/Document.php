<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

/**
 * The HTML document PD4ML has read (`com.pd4ml.dom.Document`), from {@see \Pd4ml\PD4ML::getDocument()}:
 * changes made between `readHTML()` and an output are in that output.
 *
 *     $doc = $pd->getDocument();
 *     $doc->getElementById('total')->setTextContent('1 250,00 EUR');
 *     $note = $doc->createElement('p');
 *     $note->setTextContent('Prices include VAT.');
 *     $doc->getBody()->appendChild($note);
 */
class Document extends Node
{
    /** The root element, `<html>`. */
    public function getDocumentElement(): ?Element
    {
        return self::wrap($this->java->getDocumentElement());
    }

    public function getHead(): ?Element
    {
        return self::wrap($this->java->getHead());
    }

    public function getBody(): ?Element
    {
        return self::wrap($this->java->getBody());
    }

    /** The `<!DOCTYPE>`, or null when the source has none. */
    public function getDoctype(): ?DocumentType
    {
        return self::wrap($this->java->getDoctype());
    }

    /** Whether the document is laid out in quirks mode (no doctype, or an old one). */
    public function isQuirksMode(): bool
    {
        return (bool) $this->java->isQuirksMode();
    }

    /** The base URI relative links resolve against. */
    public function getBaseURI(): ?string
    {
        return $this->java->getBaseURI();
    }

    /** The text of `<title>`. */
    public function getTitle(): ?string
    {
        return $this->java->getTitle();
    }

    /** The first element with the id, or null. */
    public function getElementById(string $id): ?Element
    {
        return self::wrap($this->java->getElementById($id));
    }

    /**
     * The elements with the tag name (`'*'`: all), in document order.
     *
     * @return Element[]
     */
    public function getElementsByTagName(string $name): array
    {
        return self::wrapList($this->java->getElementsByTagName($name));
    }

    /**
     * The elements that have all the space-separated classes, in document order.
     *
     * @return Element[]
     */
    public function getElementsByClassName(string $names): array
    {
        return self::wrapList($this->java->getElementsByClassName($names));
    }

    /** The first element matching a CSS selector list, or null. */
    public function querySelector(string $selectors): ?Element
    {
        return self::wrap($this->java->querySelector($selectors));
    }

    /**
     * All elements matching a CSS selector list, in document order.
     *
     * @return Element[]
     */
    public function querySelectorAll(string $selectors): array
    {
        return self::wrapList($this->java->querySelectorAll($selectors));
    }

    /** A new element, not yet in the tree. */
    public function createElement(string $tagName): Element
    {
        return self::wrap($this->java->createElement($tagName));
    }

    /** A new text node, not yet in the tree. */
    public function createTextNode(string $data): Text
    {
        return self::wrap($this->java->createTextNode($data));
    }

    /** A new comment, not yet in the tree. */
    public function createComment(string $data): Comment
    {
        return self::wrap($this->java->createComment($data));
    }
}
