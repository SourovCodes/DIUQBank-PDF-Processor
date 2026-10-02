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
     * @var array<string, array{opacity: float, objectNumber: int|null}>
     */
    private array $extGStates = [];

    /**
     * Draw a single line of text centred on a point and rotated anticlockwise.
     *
     * The font and text colour must already be set; they are selected outside
     * the saved graphics state so FPDF's own font tracking stays accurate.
     */
    public function rotatedText(string $text, float $centreX, float $centreY, float $angleDegrees, float $opacity): void
    {
        $angle = deg2rad($angleDegrees);
        $cos = cos($angle);
        $sin = sin($angle);
        $pivotX = $centreX * $this->k;
        $pivotY = ($this->h - $centreY) * $this->k;

        $this->_out('q');
        $this->_out(sprintf('/%s gs', $this->extGStateName($opacity)));
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

    private function extGStateName(float $opacity): string
    {
        $key = sprintf('%.3F', max(0.0, min(1.0, $opacity)));

        if (! isset($this->extGStates[$key])) {
            $this->extGStates[$key] = ['opacity' => (float) $key, 'objectNumber' => null];
        }

        return 'GS'.(array_search($key, array_keys($this->extGStates), true) + 1);
    }

    protected function _putresources(): void
    {
        foreach ($this->extGStates as $key => $state) {
            $this->_newobj();
            $this->extGStates[$key]['objectNumber'] = $this->n;
            $this->_put(sprintf('<</Type /ExtGState /ca %.3F /CA %.3F /BM /Normal>>', $state['opacity'], $state['opacity']));
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
