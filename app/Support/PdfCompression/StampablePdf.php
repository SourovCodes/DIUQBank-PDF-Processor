<?php

namespace App\Support\PdfCompression;

use setasign\Fpdi\Fpdi;

/**
 * FPDI document that can draw rotated, semi-transparent text.
 *
 * FPDF has no notion of opacity, so each distinct opacity is registered as an
 * ExtGState resource and selected with the `gs` operator around the text.
 */
class StampablePdf extends Fpdi
{
    /**
     * Blend modes a stamp may use. Multiply darkens only: on white paper the
     * stamp shows in its own colour, while black text under it stays black.
     */
    public const BLEND_MODES = ['Normal', 'Multiply', 'Darken'];

    /**
     * @var array<string, array{opacity: float, blendMode: string, objectNumber: int|null}>
     */
    private array $extGStates = [];

    /**
     * Draw a single line of text centred on a point and rotated anticlockwise.
     *
     * The font and text colour must already be set; they are selected outside
     * the saved graphics state so FPDF's own font tracking stays accurate.
     */
    public function rotatedText(string $text, float $centreX, float $centreY, float $angleDegrees, float $opacity, string $blendMode = 'Normal'): void
    {
        $angle = deg2rad($angleDegrees);
        $cos = cos($angle);
        $sin = sin($angle);
        $pivotX = $centreX * $this->k;
        $pivotY = ($this->h - $centreY) * $this->k;

        $this->_out('q');
        $this->_out(sprintf('/%s gs', $this->extGStateName($opacity, $blendMode)));
        $this->_out(sprintf(
            '%.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',
            $cos, $sin, -$sin, $cos, $pivotX, $pivotY, -$pivotX, -$pivotY,
        ));

        /*
         * Text() positions the baseline, so drop it by roughly half the cap
         * height of the core fonts to centre the glyphs vertically.
         */
        $this->Text(
            $centreX - ($this->GetStringWidth($text) / 2),
            $centreY + ($this->FontSize * 0.35),
            $text,
        );

        $this->_out('Q');
    }

    /**
     * Place an imported page so only its cropped part shows, with that part's
     * top-left corner at the given point, and blank out any hidden regions.
     *
     * The template is drawn at full size and shifted. The clipping path is the
     * crop rectangle with each hidden region cut out of it (even-odd rule), which
     * stops trimmed margins bleeding into anything drawn around the page, such
     * as the watermark header, and leaves hidden regions unpainted.
     *
     * @param  array<int, PageRegion>  $hiddenRegions
     */
    public function useCroppedTemplate(string $templateId, float $x, float $y, float $pageWidth, float $pageHeight, PageRegion $crop, array $hiddenRegions = []): void
    {
        $rectangle = fn (PageRegion $region): string => sprintf(
            '%.2F %.2F %.2F %.2F re',
            ($x + $region->offsetXOf($pageWidth) - $crop->offsetXOf($pageWidth)) * $this->k,
            ($this->h - $y - $region->offsetYOf($pageHeight) + $crop->offsetYOf($pageHeight) - $region->heightOf($pageHeight)) * $this->k,
            $region->widthOf($pageWidth) * $this->k,
            $region->heightOf($pageHeight) * $this->k,
        );

        $this->_out('q');
        $this->_out($rectangle($crop));

        /*
         * Overlapping holes would cancel each other out under the even-odd rule,
         * but each page carries at most one credit, so plain clamping suffices.
         */
        foreach ($hiddenRegions as $hiddenRegion) {
            $visiblePart = $hiddenRegion->intersect($crop);

            if ($visiblePart !== null) {
                $this->_out($rectangle($visiblePart));
            }
        }

        $this->_out('W* n');

        $this->useTemplate(
            $templateId,
            $x - $crop->offsetXOf($pageWidth),
            $y - $crop->offsetYOf($pageHeight),
            $pageWidth,
            $pageHeight,
        );

        $this->_out('Q');
    }

    /**
     * Write text that is never painted (render mode 3) but stays in the page's
     * content, where text extraction and search still find it.
     */
    public function invisibleText(float $x, float $y, string $text): void
    {
        $this->_out('q 3 Tr');
        $this->Text($x, $y, $text);
        $this->_out('Q');
    }

    private function extGStateName(float $opacity, string $blendMode): string
    {
        $blendMode = in_array($blendMode, self::BLEND_MODES, true) ? $blendMode : 'Normal';
        $opacity = round(max(0.0, min(1.0, $opacity)), 3);
        $key = sprintf('%.3F/%s', $opacity, $blendMode);

        if (! isset($this->extGStates[$key])) {
            $this->extGStates[$key] = ['opacity' => $opacity, 'blendMode' => $blendMode, 'objectNumber' => null];
        }

        return 'GS'.(array_search($key, array_keys($this->extGStates), true) + 1);
    }

    protected function _putresources(): void
    {
        foreach ($this->extGStates as $key => $state) {
            $this->_newobj();
            $this->extGStates[$key]['objectNumber'] = $this->n;
            $this->_put(sprintf('<</Type /ExtGState /ca %.3F /CA %.3F /BM /%s>>', $state['opacity'], $state['opacity'], $state['blendMode']));
            $this->_put('endobj');
        }

        parent::_putresources();
    }

    protected function _putresourcedict(): void
    {
        parent::_putresourcedict();

        if ($this->extGStates === []) {
            return;
        }

        $this->_put('/ExtGState <<');

        foreach (array_values($this->extGStates) as $index => $state) {
            $this->_put(sprintf('/GS%d %d 0 R', $index + 1, $state['objectNumber']));
        }

        $this->_put('>>');
    }

    protected function _enddoc(): void
    {
        if ($this->extGStates !== [] && $this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }

        parent::_enddoc();
    }
}
