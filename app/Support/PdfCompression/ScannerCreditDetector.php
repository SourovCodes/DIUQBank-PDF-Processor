<?php

namespace App\Support\PdfCompression;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Throwable;

/**
 * Finds the credit that scanner apps such as CamScanner stamp onto every page.
 *
 * CamScanner draws its logo and name in a corner and lays a link to its download
 * page over them. That link is the reliable signature: its rectangle covers the
 * whole credit, while the drawing itself varies between app versions. Removal
 * is a nicety, so unreadable documents and pages simply report no credits.
 */
class ScannerCreditDetector
{
    /**
     * Credit regions keyed by 1-indexed page number. Pages without a credit are
     * absent.
     *
     * @return array<int, array<int, PageRegion>>
     */
    public function creditRegionsFor(string $inputPath): array
    {
        try {
            $reader = new PdfReader(new PdfParser(StreamReader::createByFile($inputPath)));
            $pageCount = $reader->getPageCount();
        } catch (Throwable $exception) {
            Log::warning('Scanner credit detection failed; leaving credits in place.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        $regions = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            try {
                $pageRegions = $this->creditRegionsOnPage($reader, $page);
            } catch (Throwable) {
                continue;
            }

            if ($pageRegions !== []) {
                $regions[$page] = $pageRegions;
            }
        }

        return $regions;
    }

    /**
     * @return array<int, PageRegion>
     */
    private function creditRegionsOnPage(PdfReader $reader, int $pageNumber): array
    {
        $page = $reader->getPage($pageNumber);
        $size = $page->getWidthAndHeight();

        if (! is_array($size) || (float) $size[0] <= 0.0 || (float) $size[1] <= 0.0) {
            return [];
        }

        [$pageWidth, $pageHeight] = [(float) $size[0], (float) $size[1]];
        $regions = [];

        /*
         * Link rectangles come back in the visible page's own space (rotation
         * and crop box already applied) with the origin at the bottom left.
         */
        foreach ($page->getExternalLinks() as $link) {
            if (! $this->isScannerCreditLink((string) $link['uri'])) {
                continue;
            }

            $rect = $link['rect'];
            $region = (new PageRegion(
                left: $rect->getLlx() / $pageWidth,
                top: ($pageHeight - $rect->getUry()) / $pageHeight,
                right: $rect->getUrx() / $pageWidth,
                bottom: ($pageHeight - $rect->getLly()) / $pageHeight,
            ))->intersect(PageRegion::wholePage());

            if ($region !== null) {
                $regions[] = $region;
            }
        }

        return $regions;
    }

    private function isScannerCreditLink(string $uri): bool
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        foreach ((array) config('pdf.scanner_credit.link_domains') as $domain) {
            $domain = strtolower((string) $domain);

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
