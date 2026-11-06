<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

/**
 * An element (`com.pd4ml.dom.Element`): attributes, classes, element navigation, CSS selector
 * queries and inner/outer HTML.
 *
 *     foreach ($doc->querySelectorAll('img:not([alt])') as $img) {
 *         $img->setAttribute('alt', '');
 *     }
 *
 * The searches look only inside the element. An invalid selector throws a
 * {@see \Pd4ml\JavaException} (`java.lang.IllegalArgumentException`).
 */
class Element extends Node
{
    /** The tag name, lower case. */
    public function getTagName(): string
    {
        return (string) $this->java->getTagName();
    }

    /** An attribute's value (entities decoded), or null if it is absent. Names are case-insensitive. */
    public function getAttribute(string $name): ?string
    {
        return $this->java->getAttribute($name);
    }

    public function hasAttribute(string $name): bool
    {
        return (bool) $this->java->hasAttribute($name);
    }

    public function setAttribute(string $name, string $value): void
    {
        $this->java->setAttribute($name, $value);
    }

    public function removeAttribute(string $name): void
    {
        $this->java->removeAttribute($name);
    }

    /**
     * All attributes, in source order.
     *
     * @return array<string, string> name => value
     */
    public function getAttributes(): array
    {
        $map = $this->java->getAttributes();
        $names = self::javaList($map->keySet());
        $values = self::javaList($map->values());
        return $names ? array_combine(array_map('strval', $names), $values) : [];
    }

    /** The `id` attribute, or null. */
    public function getId(): ?string
    {
        return $this->java->getId();
    }

    public function setId(string $id): void
    {
        $this->java->setId($id);
    }

    /** The `class` attribute, or null. */
    public function getClassName(): ?string
    {
        return $this->java->getClassName();
    }

    public function setClassName(string $className): void
    {
        $this->java->setClassName($className);
    }

    /**
     * The classes, in order.
     *
     * @return string[]
     */
    public function getClassList(): array
    {
        return self::javaList($this->java->getClassList());
    }

    public function hasClass(string $name): bool
    {
        return (bool) $this->java->hasClass($name);
    }

    public function addClass(string $name): void
    {
        $this->java->addClass($name);
    }

    public function removeClass(string $name): void
    {
        $this->java->removeClass($name);
    }

    /** Adds the class if it is not there, removes it otherwise; returns whether it is there now. */
    public function toggleClass(string $name): bool
    {
        return (bool) $this->java->toggleClass($name);
    }

    /**
     * The child elements.
     *
     * @return Element[]
     */
    public function getChildren(): array
    {
        return self::wrapList($this->java->getChildren());
    }

    public function getFirstElementChild(): ?Element
    {
        return self::wrap($this->java->getFirstElementChild());
    }

    public function getLastElementChild(): ?Element
    {
        return self::wrap($this->java->getLastElementChild());
    }

    public function getNextElementSibling(): ?Element
    {
        return self::wrap($this->java->getNextElementSibling());
    }

    public function getPreviousElementSibling(): ?Element
    {
        return self::wrap($this->java->getPreviousElementSibling());
    }

    /**
     * The elements inside with the tag name (`'*'`: all), in document order.
     *
     * @return Element[]
     */
    public function getElementsByTagName(string $name): array
    {
        return self::wrapList($this->java->getElementsByTagName($name));
    }

    /**
     * The elements inside that have all the space-separated classes, in document order.
     *
     * @return Element[]
     */
    public function getElementsByClassName(string $names): array
    {
        return self::wrapList($this->java->getElementsByClassName($names));
    }

    /** The first element inside matching a CSS selector list, or null. */
    public function querySelector(string $selectors): ?Element
    {
        return self::wrap($this->java->querySelector($selectors));
    }

    /**
     * All elements inside matching a CSS selector list, in document order.
     *
     * @return Element[]
     */
    public function querySelectorAll(string $selectors): array
    {
        return self::wrapList($this->java->querySelectorAll($selectors));
    }

    /** Whether this element matches a CSS selector list. */
    public function matches(string $selectors): bool
    {
        return (bool) $this->java->matches($selectors);
    }

    /** This element or its nearest ancestor matching a CSS selector list, or null. */
    public function closest(string $selectors): ?Element
    {
        return self::wrap($this->java->closest($selectors));
    }

    /** The HTML of the element's content. */
    public function getInnerHTML(): string
    {
        return (string) $this->java->getInnerHTML();
    }

    /**
     * Replaces the content with the HTML, parsed as the content of an element of this tag (table
     * rows into a `<tbody>`...); for `<script>`, `<style>`, `<textarea>`, `<title>` it is set as text.
     */
    public function setInnerHTML(string $html): void
    {
        $this->java->setInnerHTML($html);
    }

    /** The HTML of the element itself. */
    public function getOuterHTML(): string
    {
        return (string) $this->java->getOuterHTML();
    }

    /** Replaces the element with the nodes the HTML parses into (the element must have a parent element). */
    public function setOuterHTML(string $html): void
    {
        $this->java->setOuterHTML($html);
    }
}
