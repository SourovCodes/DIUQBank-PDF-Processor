<?php

namespace App\Support\PdfCompression;

/**
 * Page-relative geometry for the watermark banner.
 *
 * Every dimension is derived from the page width so the banner keeps the same
 * visual weight on a 74mm scanner page as it does on A4, and the label is always
 * shrunk (and, as a last resort, truncated) to fit inside the page.
 */
class WatermarkHeader
{
    private const ELLIPSIS = '...';

    public readonly float $heightMm;

    public readonly float $sidePaddingMm;

    public readonly float $nominalFontSize;

    public function __construct(public readonly float $pageWidthMm)
    {
        $this->heightMm = $this->clamp(
            $pageWidthMm * (float) config('pdf.watermark.header_height_ratio'),
            (float) config('pdf.watermark.min_header_height_mm'),
            (float) config('pdf.watermark.max_header_height_mm'),
        );

        $this->sidePaddingMm = max(
            (float) config('pdf.watermark.min_side_padding_mm'),
            $pageWidthMm * (float) config('pdf.watermark.side_padding_ratio'),
        );

        $this->nominalFontSize = max(
            (float) config('pdf.watermark.min_font_size'),
            $this->heightMm * (float) config('pdf.watermark.font_size_per_mm'),
        );
    }

    /**
     * Width available to the label, in millimetres.
     */
    public function textWidthMm(): float
    {
        return max(0.0, $this->pageWidthMm - ($this->sidePaddingMm * 2));
    }

    /**
     * Pick the largest font size at or below the nominal size that fits the label
     * inside the page, truncating the label when even the smallest size overflows.
     *
     * @param  callable(string, float): float  $measure  Renders a label width in mm for a given font size
     * @return array{text: string, fontSize: float}
     */
    public function fitLabel(string $text, callable $measure): array
    {
        $available = $this->textWidthMm();
        $minimumFontSize = (float) config('pdf.watermark.min_font_size');

        for ($fontSize = $this->nominalFontSize; $fontSize > $minimumFontSize; $fontSize -= 0.25) {
            if ($measure($text, $fontSize) <= $available) {
                return ['text' => $text, 'fontSize' => $fontSize];
            }
        }

        return [
            'text' => $this->truncateToWidth($text, $minimumFontSize, $available, $measure),
            'fontSize' => $minimumFontSize,
        ];
    }

    /**
     * @param  callable(string, float): float  $measure
     */
    private function truncateToWidth(string $text, float $fontSize, float $available, callable $measure): string
    {
        if ($measure($text, $fontSize) <= $available) {
            return $text;
        }

        $length = mb_strlen($text);

        while ($length > 0) {
            $candidate = rtrim(mb_substr($text, 0, $length)).self::ELLIPSIS;

            if ($measure($candidate, $fontSize) <= $available) {
                return $candidate;
            }

            $length--;
        }

        return '';
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return min(max($value, $minimum), $maximum);
    }
}
