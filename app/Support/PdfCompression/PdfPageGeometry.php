<?php

namespace App\Support\PdfCompression;

use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Throwable;

/**
 * Reads page dimensions straight from the PDF cross-reference table.
 *
 * Damaged documents routinely fail on individual pages, so every page is probed
 * independently and unreadable pages are skipped rather than aborting the whole
 * document.
 */
class PdfPageGeometry
{
    /**
     * Width in points of the narrowest readable page, or null when no page could
     * be parsed.
     *
     * The narrowest page drives image resolution because it is the page whose
     * images Ghostscript would degrade the most.
     */
    public function narrowestPageWidthInPoints(string $path): ?float
    {
        $widths = $this->pageWidthsInPoints($path);

        return $widths === [] ? null : min($widths);
    }

    /**
     * @return array<int, float>
     */
    public function pageWidthsInPoints(string $path): array
    {
        try {
            $reader = new PdfReader(new PdfParser(StreamReader::createByFile($path)));
            $pageCount = $reader->getPageCount();
        } catch (Throwable) {
            return [];
        }

        $widths = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            try {
                $dimensions = $reader->getPage($page)->getWidthAndHeight();
            } catch (Throwable) {
                continue;
            }

            if (! is_array($dimensions) || ! isset($dimensions[0]) || ! is_numeric($dimensions[0])) {
                continue;
            }

            $width = (float) $dimensions[0];

            if ($width > 0.0) {
                $widths[] = $width;
            }
        }

        return $widths;
    }
}
