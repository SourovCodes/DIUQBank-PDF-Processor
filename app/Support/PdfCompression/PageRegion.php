<?php

namespace App\Support\PdfCompression;

/**
 * A rectangle on a page, as fractions of the page's width and height measured
 * from its top-left corner: the part of a page worth keeping, or a part to hide.
 *
 * Fractions keep regions independent of units: Ghostscript measures the page in
 * rendered pixels, FPDI in millimetres and PDF links in points, and all three see
 * the same visible page.
 */
class PageRegion
{
    public function __construct(
        public readonly float $left,
        public readonly float $top,
        public readonly float $right,
        public readonly float $bottom,
    ) {}

    public static function wholePage(): self
    {
        return new self(0.0, 0.0, 1.0, 1.0);
    }

    public function widthOf(float $pageWidth): float
    {
        return $pageWidth * ($this->right - $this->left);
    }

    public function heightOf(float $pageHeight): float
    {
        return $pageHeight * ($this->bottom - $this->top);
    }

    public function offsetXOf(float $pageWidth): float
    {
        return $pageWidth * $this->left;
    }

    public function offsetYOf(float $pageHeight): float
    {
        return $pageHeight * $this->top;
    }

    /**
     * The overlap with another region, or null when they do not overlap.
     */
    public function intersect(self $other): ?self
    {
        $left = max($this->left, $other->left);
        $top = max($this->top, $other->top);
        $right = min($this->right, $other->right);
        $bottom = min($this->bottom, $other->bottom);

        return $left < $right && $top < $bottom ? new self($left, $top, $right, $bottom) : null;
    }
}
