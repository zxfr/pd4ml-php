<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

use Pd4ml\JavaObject;

/**
 * The document laid out into pages as `writePDF()` would write it (`com.pd4ml.dom.Layout`), from
 * {@see \Pd4ml\PD4ML::layout()}: where each element is, on which page, and its computed style.
 * Positions and sizes are in PDF points from the top left corner of a page; pages count from 1.
 *
 *     $layout = $pd->layout();
 *     if (count($layout->getPages($doc->getElementById('totals'))) > 1) {
 *         $doc->getElementById('totals')->setAttribute('style', 'page-break-before: always');
 *     }
 *
 * A layout is a snapshot: after changing the document, call `layout()` again.
 */
final class Layout implements \Stringable
{
    /** @internal use {@see \Pd4ml\PD4ML::layout()} */
    public function __construct(
        /** The Java object (`com.pd4ml.dom.Layout`). */
        public readonly JavaObject $java,
    ) {
    }

    /** The number of pages. */
    public function getPageCount(): int
    {
        return (int) $this->java->getPageCount();
    }

    /** The width of a page (1 for the first one), in points. */
    public function getPageWidth(int $page): float
    {
        return (float) $this->java->getPageWidth($page);
    }

    /** The height of a page (1 for the first one), in points. */
    public function getPageHeight(int $page): float
    {
        return (float) $this->java->getPageHeight($page);
    }

    /** The page number printed on a page (`$[page]`, after `setInitialPageNumber()` and counter resets). */
    public function getPageNumber(int $page): int
    {
        return (int) $this->java->getPageNumber($page);
    }

    /**
     * The boxes of an element, in page order; empty if it is not displayed (`display: none`, in
     * `<head>`...). An element continued on the next page has a box on each page, an inline element
     * broken over lines a box per line.
     *
     * @return LayoutBox[]
     */
    public function getBoxes(?Element $element): array
    {
        return array_map(static fn (JavaObject $box) => LayoutBox::of($box),
            Node::javaList($this->java->getBoxes($element?->java)));
    }

    /**
     * The pages an element is on, in order; empty if it is not displayed.
     *
     * @return int[]
     */
    public function getPages(?Element $element): array
    {
        return array_map('intval', Node::javaList($this->java->getPages($element?->java)));
    }

    /**
     * The computed value of a CSS property of an element (`color`, `font-size`, `display`...) as CSS
     * text, or null if the element is not displayed.
     */
    public function getComputedStyle(?Element $element, string $property): ?string
    {
        return $this->java->getComputedStyle($element?->java, $property);
    }

    /** The innermost element at a point of a page (in points from its top left corner), or null. */
    public function elementAt(int $page, float $x, float $y): ?Element
    {
        return Node::wrap($this->java->elementAt($page, $x, $y));
    }

    /** Java methods not declared here. */
    public function __call(string $method, array $args): mixed
    {
        return $this->java->__call($method, $args);
    }

    public function __toString(): string
    {
        return (string) $this->java;
    }
}
