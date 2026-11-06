<?php

declare(strict_types=1);

namespace Pd4ml\Dom;

use Pd4ml\JavaObject;

/**
 * A box of an element on a page ({@see Layout::getBoxes()}): its border box, in PDF points from the
 * top left corner of the page. A PHP value object with the values of `com.pd4ml.dom.LayoutBox`.
 */
final class LayoutBox implements \Stringable
{
    public function __construct(
        /** The page, 1 for the first one. */
        public readonly int $page,
        /** Distance from the left edge of the page. */
        public readonly float $x,
        /** Distance from the top edge of the page. */
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
    ) {
    }

    /** @internal a box from a Java `com.pd4ml.dom.LayoutBox` */
    public static function of(JavaObject $box): self
    {
        return new self((int) $box->getPage(), (float) $box->getX(), (float) $box->getY(),
            (float) $box->getWidth(), (float) $box->getHeight());
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }

    /** As Java's: `page 2: 56.69, 640.10, 481.89 x 85.00 pt`. */
    public function __toString(): string
    {
        return sprintf('page %d: %.2F, %.2F, %.2F x %.2F pt', $this->page, $this->x, $this->y, $this->width, $this->height);
    }
}
