<?php

namespace App\Support\PdfCompression;

use Random\Randomizer;

/**
 * Geometry for the faint watermark stamps scattered across a page's content.
 *
 * The page is split into horizontal bands, one stamp per band, so stamps never
 * overlap yet still cover the whole page. Each stamp gets a random angle and a
 * random position inside its band, which makes it impossible to hide them all
 * behind a single patch laid over a known spot. Its text also follows a random
 * wave, so no straight-line tool can select or erase it in one stroke.
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
     * @return array<int, array{text: string, fontSize: float, angleDegrees: float, x: float, y: float, curve: array{amplitudeMm: float, cycles: float, phase: float}}>
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

            $labelWidth = $measure($label['text'], $label['fontSize']);
            $curve = $this->randomCurve($randomizer, $labelWidth);
            [$boxWidth, $boxHeight] = $this->boundingBox($labelWidth, $label['fontSize'], $angle, $curve['amplitudeMm']);
            $bandTop = $band * $bandHeight;

            $placements[] = [
                ...$label,
                'angleDegrees' => $angle,
                'curve' => $curve,
                'x' => $this->randomBetween($randomizer, $boxWidth / 2, $this->areaWidthMm - ($boxWidth / 2)),
                'y' => $this->randomBetween($randomizer, $bandTop + ($boxHeight / 2), $bandTop + $bandHeight - ($boxHeight / 2)),
            ];
        }

        return $placements;
    }

    /**
     * Longest label width, in millimetres, whose rotated bounding box still fits
     * inside the coverage share of a box at the given font size and angle.
     *
     * The wave can swing the text up to `max_amplitude_ratio` of its width to
     * either side of the baseline, so that much is reserved on top of the
     * line height.
     */
    public function maxLabelWidthMm(float $fontSize, float $angleDegrees, float $boxWidthMm, float $boxHeightMm): float
    {
        $coverage = (float) config('pdf.watermark.stamp.coverage');
        $swing = 2 * $this->maxAmplitudeRatio();
        $lineHeight = $fontSize * self::MM_PER_POINT;
        $angle = deg2rad($angleDegrees);
        $cos = abs(cos($angle));
        $sin = abs(sin($angle));

        $limits = [];

        if ($cos + ($swing * $sin) > 1e-9) {
            $limits[] = (($boxWidthMm * $coverage) - ($lineHeight * $sin)) / ($cos + ($swing * $sin));
        }

        if ($sin + ($swing * $cos) > 1e-9) {
            $limits[] = (($boxHeightMm * $coverage) - ($lineHeight * $cos)) / ($sin + ($swing * $cos));
        }

        return max(0.0, min($limits));
    }

    /**
     * Width and height of the rotated label's bounding box, in millimetres,
     * with room for the wave to swing either side of the baseline.
     *
     * @return array{0: float, 1: float}
     */
    public function boundingBox(float $labelWidthMm, float $fontSize, float $angleDegrees, float $curveAmplitudeMm = 0.0): array
    {
        $thickness = ($fontSize * self::MM_PER_POINT) + (2 * $curveAmplitudeMm);
        $angle = deg2rad($angleDegrees);
        $cos = abs(cos($angle));
        $sin = abs(sin($angle));

        return [
            ($labelWidthMm * $cos) + ($thickness * $sin),
            ($labelWidthMm * $sin) + ($thickness * $cos),
        ];
    }

    /**
     * Lay a placement's characters along its wave: the centre of each glyph,
     * relative to the area's top-left corner, and the angle that keeps the
     * glyph upright on the curve. Spaces only advance the pen.
     *
     * @param  array{text: string, fontSize: float, angleDegrees: float, x: float, y: float, curve: array{amplitudeMm: float, cycles: float, phase: float}}  $placement
     * @param  callable(string, float): float  $measure
     * @return array<int, array{text: string, x: float, y: float, angleDegrees: float}>
     */
    public function glyphs(array $placement, callable $measure): array
    {
        $characters = mb_str_split($placement['text']);
        $widths = array_map(fn (string $character): float => $measure($character, $placement['fontSize']), $characters);
        $path = $this->wavePath(array_sum($widths), $placement['curve']);

        $angle = deg2rad($placement['angleDegrees']);
        [$cos, $sin] = [cos($angle), sin($angle)];
        $glyphs = [];
        $pen = 0.0;

        foreach ($characters as $index => $character) {
            [$u, $v, $tangent] = $this->pointAt($path, $pen + ($widths[$index] / 2));
            $pen += $widths[$index];

            if (trim($character) === '') {
                continue;
            }

            /*
             * FPDF's y axis points down the page, so "along the text" is
             * (cos, -sin) and "up from the text" is (-sin, -cos).
             */
            $glyphs[] = [
                'text' => $character,
                'x' => $placement['x'] + ($u * $cos) - ($v * $sin),
                'y' => $placement['y'] - ($u * $sin) - ($v * $cos),
                'angleDegrees' => $placement['angleDegrees'] + rad2deg($tangent),
            ];
        }

        return $glyphs;
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
     * @return array{amplitudeMm: float, cycles: float, phase: float}
     */
    private function randomCurve(Randomizer $randomizer, float $labelWidthMm): array
    {
        return [
            'amplitudeMm' => $labelWidthMm * $this->randomBetween(
                $randomizer,
                min((float) config('pdf.watermark.stamp.curve.min_amplitude_ratio'), $this->maxAmplitudeRatio()),
                $this->maxAmplitudeRatio(),
            ),
            'cycles' => $this->randomBetween(
                $randomizer,
                (float) config('pdf.watermark.stamp.curve.min_cycles'),
                (float) config('pdf.watermark.stamp.curve.max_cycles'),
            ),
            'phase' => $this->randomBetween($randomizer, 0.0, 2 * M_PI),
        ];
    }

    private function maxAmplitudeRatio(): float
    {
        return max(0.0, (float) config('pdf.watermark.stamp.curve.max_amplitude_ratio'));
    }

    /**
     * Sample a sine wave whose length along the curve equals the label width,
     * centred on the origin so the label stays centred on its placement.
     *
     * @param  array{amplitudeMm: float, cycles: float, phase: float}  $curve
     * @return array<int, array{u: float, v: float, s: float}> points with their distance along the curve
     */
    private function wavePath(float $labelWidthMm, array $curve): array
    {
        $samples = 200;
        $span = $labelWidthMm;

        // The curve is longer than its span, so shrink the span until it fits.
        for ($pass = 0; $pass < 4; $pass++) {
            $path = [];
            $length = 0.0;

            for ($index = 0; $index <= $samples; $index++) {
                $u = $span * (($index / $samples) - 0.5);
                $v = $curve['amplitudeMm'] * sin((2 * M_PI * $curve['cycles'] * $index / $samples) + $curve['phase']);

                if ($index > 0) {
                    $previous = $path[$index - 1];
                    $length += hypot($u - $previous['u'], $v - $previous['v']);
                }

                $path[] = ['u' => $u, 'v' => $v, 's' => $length];
            }

            if ($length <= 0.0 || abs($length - $labelWidthMm) < 0.01) {
                break;
            }

            $span *= $labelWidthMm / $length;
        }

        $vs = array_column($path, 'v');
        $middle = (max($vs) + min($vs)) / 2;

        return array_map(fn (array $point): array => [...$point, 'v' => $point['v'] - $middle], $path);
    }

    /**
     * The point at a distance along the path, and the path's direction there.
     *
     * @param  array<int, array{u: float, v: float, s: float}>  $path
     * @return array{0: float, 1: float, 2: float}
     */
    private function pointAt(array $path, float $distance): array
    {
        $last = count($path) - 1;

        $index = 1;

        while ($index < $last && $path[$index]['s'] < $distance) {
            $index++;
        }

        $from = $path[$index - 1];
        $to = $path[$index];
        $segment = $to['s'] - $from['s'];
        $share = $segment > 0.0 ? max(0.0, min(1.0, ($distance - $from['s']) / $segment)) : 0.0;

        return [
            $from['u'] + (($to['u'] - $from['u']) * $share),
            $from['v'] + (($to['v'] - $from['v']) * $share),
            atan2($to['v'] - $from['v'], $to['u'] - $from['u']),
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
