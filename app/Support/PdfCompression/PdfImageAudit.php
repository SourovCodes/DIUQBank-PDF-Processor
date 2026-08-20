<?php

namespace App\Support\PdfCompression;

use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfReader\PdfReader;
use Throwable;

/**
 * Inspects the image XObjects a document draws with.
 *
 * Damaged documents routinely fail on individual pages or objects, so every
 * lookup is probed independently and unreadable branches are skipped rather
 * than aborting the whole document.
 */
class PdfImageAudit
{
    /**
     * Object numbers already visited, guarding against resource cycles.
     *
     * @var array<int, true>
     */
    private array $visited = [];

    /**
     * Whether any image stores 16 bits per colour component.
     *
     * Ghostscript's colour conversion silently discards such images — the page
     * survives, but it comes out blank — so callers use this to decide whether
     * the conversion is safe to ask for.
     */
    public function hasSixteenBitImages(string $path): bool
    {
        $this->visited = [];

        try {
            $parser = new PdfParser(StreamReader::createByFile($path));
            $reader = new PdfReader($parser);
            $pageCount = $reader->getPageCount();
        } catch (Throwable) {
            return false;
        }

        for ($page = 1; $page <= $pageCount; $page++) {
            try {
                $resources = PdfType::resolve($reader->getPage($page)->getAttribute('Resources'), $parser);
            } catch (Throwable) {
                continue;
            }

            if ($resources instanceof PdfDictionary && $this->resourcesHaveSixteenBitImage($resources, $parser)) {
                return true;
            }
        }

        return false;
    }

    private function resourcesHaveSixteenBitImage(PdfDictionary $resources, PdfParser $parser): bool
    {
        try {
            $xObjects = PdfType::resolve(PdfDictionary::get($resources, 'XObject'), $parser);
        } catch (Throwable) {
            return false;
        }

        if (! $xObjects instanceof PdfDictionary) {
            return false;
        }

        foreach ($xObjects->value as $reference) {
            if ($this->alreadyVisited($reference)) {
                continue;
            }

            try {
                $xObject = PdfType::resolve($reference, $parser);
            } catch (Throwable) {
                continue;
            }

            if (! $xObject instanceof PdfStream) {
                continue;
            }

            if ($this->xObjectHasSixteenBitImage($xObject->value, $parser)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A form XObject carries its own resource dictionary, so nested drawings are
     * followed rather than assumed to be image-free.
     */
    private function xObjectHasSixteenBitImage(PdfDictionary $dictionary, PdfParser $parser): bool
    {
        $subtype = $this->nameValue($dictionary, 'Subtype', $parser);

        if ($subtype === 'Image') {
            return $this->numericValue($dictionary, 'BitsPerComponent', $parser) === 16.0;
        }

        if ($subtype !== 'Form') {
            return false;
        }

        try {
            $resources = PdfType::resolve(PdfDictionary::get($dictionary, 'Resources'), $parser);
        } catch (Throwable) {
            return false;
        }

        return $resources instanceof PdfDictionary
            && $this->resourcesHaveSixteenBitImage($resources, $parser);
    }

    private function alreadyVisited(PdfType $reference): bool
    {
        if (! $reference instanceof PdfIndirectObjectReference) {
            return false;
        }

        $objectNumber = (int) $reference->value;

        if (isset($this->visited[$objectNumber])) {
            return true;
        }

        $this->visited[$objectNumber] = true;

        return false;
    }

    private function nameValue(PdfDictionary $dictionary, string $key, PdfParser $parser): ?string
    {
        try {
            $value = PdfType::resolve(PdfDictionary::get($dictionary, $key), $parser);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof PdfName ? (string) $value->value : null;
    }

    private function numericValue(PdfDictionary $dictionary, string $key, PdfParser $parser): ?float
    {
        try {
            $value = PdfType::resolve(PdfDictionary::get($dictionary, $key), $parser);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof PdfNumeric ? (float) $value->value : null;
    }
}
