<?php

namespace App\Support\PdfCompression;

use Random\Randomizer;

/**
 * Geometry for the faint watermark stamps scattered across a page's content.
 *
 * The page is split into horizontal bands, one stamp per band, so stamps never
 * overlap yet still cover the whole page. Each stamp gets a random angle and a
 * random position inside its band, which makes it impossible to hide them all
 * behind a single patch laid over a known spot.
 */
class WatermarkStamp
{
    private const ELLIPSIS = '...';

    private const MM_PER_POINT = 25.4 / 72;

    public readonly float $nominalFontSize;

    public function __construct(
        public readonly float $areaWidthMm,
        public readonly float $areaHeightMm,
    ) {
        $this->nominalFontSize = min(
            (float) config('pdf.watermark.stamp.max_font_size'),
            max(
                (float) config('pdf.watermark.stamp.min_font_size'),
                min($areaWidthMm, $areaHeightMm) * (float) config('pdf.watermark.stamp.font_size_per_mm'),
            ),
        );
    }

    /**
     * Pick a random angle, fit the label to it and place it somewhere random
     * inside each band. Positions are relative to the area's top-left corner.
     *
     * @param  callable(string, float): float  $measure  Renders a label width in mm for a given font size
     * @return array<int, array{text: string, fontSize: float, angleDegrees: float, x: float, y: float}>
     */
    public function placements(string $text, callable $measure, Randomizer $randomizer): array
    {
        $bandCount = max(1, (int) config('pdf.watermark.stamp.stamps_per_page'));
        $bandHeight = $this->areaHeightMm / $bandCount;
        $placements = [];

        for ($band = 0; $band < $bandCount; $band++) {
            $angle = $this->randomAngle($randomizer);
            $label = $this->fitLabel($text, $measure, $angle, $this->areaWidthMm, $bandHeight);

            if ($label['text'] === '') {
                continue;
            }

            [$boxWidth, $boxHeight] = $this->boundingBox($measure($label['text'], $label['fontSize']), $label['fontSize'], $angle);
            $bandTop = $band * $bandHeight;

            $placements[] = [
                ...$label,
                'angleDegrees' => $angle,
                'x' => $this->randomBetween($randomizer, $boxWidth / 2, $this->areaWidthMm - ($boxWidth / 2)),
                'y' => $this->randomBetween($randomizer, $bandTop + ($boxHeight / 2), $bandTop + $bandHeight - ($boxHeight / 2)),
            ];
        }

        return $placements;
    }

    /**
     * Longest label width, in millimetres, whose rotated bounding box still fits
     * inside the coverage share of a box at the given font size and angle.
     */
    public function maxLabelWidthMm(float $fontSize, float $angleDegrees, float $boxWidthMm, float $boxHeightMm): float
    {
        $coverage = (float) config('pdf.watermark.stamp.coverage');
        $lineHeight = $fontSize * self::MM_PER_POINT;
        $angle = deg2rad($angleDegrees);
        $cos = abs(cos($angle));
        $sin = abs(sin($angle));

        $limits = [];

        if ($cos > 1e-9) {
            $limits[] = (($boxWidthMm * $coverage) - ($lineHeight * $sin)) / $cos;
        }

        if ($sin > 1e-9) {
            $limits[] = (($boxHeightMm * $coverage) - ($lineHeight * $cos)) / $sin;
        }

        return max(0.0, min($limits));
    }

    /**
     * Width and height of the rotated label's bounding box, in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public function boundingBox(float $labelWidthMm, float $fontSize, float $angleDegrees): array
    {
        $lineHeight = $fontSize * self::MM_PER_POINT;
        $angle = deg2rad($angleDegrees);
        $cos = abs(cos($angle));
        $sin = abs(sin($angle));

        return [
            ($labelWidthMm * $cos) + ($lineHeight * $sin),
            ($labelWidthMm * $sin) + ($lineHeight * $cos),
        ];
    }

    /**
     * Pick the largest font size at or below the nominal size that fits the
     * label in the box, truncating it when even the smallest size overflows.
     *
     * @param  callable(string, float): float  $measure
     * @return array{text: string, fontSize: float}
     */
    public function fitLabel(string $text, callable $measure, float $angleDegrees, float $boxWidthMm, float $boxHeightMm): array
    {
        $minimumFontSize = (float) config('pdf.watermark.stamp.min_font_size');

        for ($fontSize = $this->nominalFontSize; $fontSize > $minimumFontSize; $fontSize -= 0.5) {
            if ($measure($text, $fontSize) <= $this->maxLabelWidthMm($fontSize, $angleDegrees, $boxWidthMm, $boxHeightMm)) {
                return ['text' => $text, 'fontSize' => $fontSize];
            }
        }

        $available = $this->maxLabelWidthMm($minimumFontSize, $angleDegrees, $boxWidthMm, $boxHeightMm);

        return [
            'text' => $this->truncateToWidth($text, $minimumFontSize, $available, $measure),
            'fontSize' => $minimumFontSize,
        ];
    }

    /**
     * A random tilt in either direction, kept away from horizontal and vertical.
     */
    private function randomAngle(Randomizer $randomizer): float
    {
        $angle = $this->randomBetween(
            $randomizer,
            (float) config('pdf.watermark.stamp.min_angle_degrees'),
            (float) config('pdf.watermark.stamp.max_angle_degrees'),
        );

        return $randomizer->getInt(0, 1) === 1 ? $angle : -$angle;
    }

    private function randomBetween(Randomizer $randomizer, float $minimum, float $maximum): float
    {
        if ($maximum <= $minimum) {
            return ($minimum + $maximum) / 2;
        }

        return $randomizer->getFloat($minimum, $maximum);
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
}
