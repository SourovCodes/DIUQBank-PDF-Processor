<?php

namespace App\Support\PdfCompression;

/**
 * How pages are reshaped before compression: whether white margins are cropped
 * away, whether scanner app credits are hidden, and the width every page is
 * scaled to (heights follow, so each page keeps its aspect ratio). The defaults
 * leave pages exactly as they are.
 */
class PageLayout
{
    public function __construct(
        public readonly bool $trimWhitespace = false,
        public readonly ?float $pageWidthMm = null,
        public readonly bool $removeScannerCredits = false,
    ) {}

    public static function fromConfig(bool $trimWhitespace, bool $removeScannerCredits): self
    {
        $pageWidthMm = (float) config('pdf.layout.page_width_mm');

        return new self($trimWhitespace, $pageWidthMm > 0 ? $pageWidthMm : null, $removeScannerCredits);
    }

    /**
     * Width and height of a page once it is scaled to the uniform width.
     *
     * @return array{0: float, 1: float}
     */
    public function scaledSize(float $width, float $height): array
    {
        if ($this->pageWidthMm === null || $width <= 0.0) {
            return [$width, $height];
        }

        return [$this->pageWidthMm, $height * $this->pageWidthMm / $width];
    }
}
