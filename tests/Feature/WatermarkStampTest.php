<?php

use App\Support\PdfCompression\StampablePdf;
use App\Support\PdfCompression\WatermarkStamp;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Measures a label as though each character were 0.1mm wide per point of font
 * size, which keeps the expectations below independent of font metrics.
 */
function measureStampLabel(): Closure
{
    return fn (string $text, float $fontSize): float => mb_strlen($text) * $fontSize * 0.1;
}

function seededRandomizer(int $seed = 42): Randomizer
{
    return new Randomizer(new Mt19937($seed));
}

test('stamp font size scales with the shorter side of the page', function (): void {
    expect((new WatermarkStamp(210.0, 297.0))->nominalFontSize)->toEqualWithDelta(36.0, 0.001)
        ->and((new WatermarkStamp(297.0, 210.0))->nominalFontSize)->toEqualWithDelta(36.0, 0.001)
        ->and((new WatermarkStamp(74.0, 105.0))->nominalFontSize)->toBeLessThan(36.0);
});

test('stamp font size is clamped to the configured range', function (): void {
    expect((new WatermarkStamp(10.0, 10.0))->nominalFontSize)->toBe(8.0)
        ->and((new WatermarkStamp(5000.0, 5000.0))->nominalFontSize)->toBe(120.0);
});

test('three stamps are placed on the page by default', function (): void {
    $placements = (new WatermarkStamp(210.0, 297.0))->placements('DIUQBank.com', measureStampLabel(), seededRandomizer());

    expect($placements)->toHaveCount(3);
});

test('one stamp is placed in each band of the page', function (): void {
    config()->set('pdf.watermark.stamp.stamps_per_page', 3);

    $placements = (new WatermarkStamp(210.0, 297.0))->placements('DIUQBank.com', measureStampLabel(), seededRandomizer());

    expect($placements)->toHaveCount(3)
        ->and($placements[0]['y'])->toBeLessThan(99.0)
        ->and($placements[1]['y'])->toBeGreaterThan(99.0)->toBeLessThan(198.0)
        ->and($placements[2]['y'])->toBeGreaterThan(198.0);
});

test('stamps get random tilts within the configured range', function (): void {
    $angles = collect(range(1, 20))
        ->flatMap(fn (int $seed): array => (new WatermarkStamp(210.0, 297.0))->placements('DIUQBank.com', measureStampLabel(), seededRandomizer($seed)))
        ->pluck('angleDegrees');

    expect($angles->every(fn (float $angle): bool => abs($angle) >= 15.0 && abs($angle) <= 65.0))->toBeTrue()
        ->and($angles->contains(fn (float $angle): bool => $angle > 0))->toBeTrue()
        ->and($angles->contains(fn (float $angle): bool => $angle < 0))->toBeTrue()
        ->and($angles->unique()->count())->toBe($angles->count());
});

test('stamps land in different places on every page', function (): void {
    $stamp = new WatermarkStamp(210.0, 297.0);

    $first = $stamp->placements('DIUQBank.com', measureStampLabel(), seededRandomizer(1));
    $second = $stamp->placements('DIUQBank.com', measureStampLabel(), seededRandomizer(2));

    expect($first)->not->toBe($second);
});

test('every stamp stays inside its band and the page', function (string $text, float $width, float $height): void {
    config()->set('pdf.watermark.stamp.stamps_per_page', 3);
    $stamp = new WatermarkStamp($width, $height);
    $bandHeight = $height / 3;

    foreach (range(1, 25) as $seed) {
        foreach ($stamp->placements($text, measureStampLabel(), seededRandomizer($seed)) as $band => $placement) {
            [$boxWidth, $boxHeight] = $stamp->boundingBox(
                measureStampLabel()($placement['text'], $placement['fontSize']),
                $placement['fontSize'],
                $placement['angleDegrees'],
                $placement['curve']['amplitudeMm'],
            );

            expect($placement['x'] - ($boxWidth / 2))->toBeGreaterThanOrEqual(-0.001)
                ->and($placement['x'] + ($boxWidth / 2))->toBeLessThanOrEqual($width + 0.001)
                ->and($placement['y'] - ($boxHeight / 2))->toBeGreaterThanOrEqual(($band * $bandHeight) - 0.001)
                ->and($placement['y'] + ($boxHeight / 2))->toBeLessThanOrEqual((($band + 1) * $bandHeight) + 0.001);
        }
    }
})->with([
    'a4 portrait' => ['DIUQBank.com', 210.0, 297.0],
    'a4 landscape' => ['DIUQBank.com', 297.0, 210.0],
    'small scanner page' => ['DIUQBank.com', 74.0, 105.0],
    'long label' => [str_repeat('DIUQBank.com ', 8), 210.0, 297.0],
]);

test('stamp labels are truncated when even the smallest font overflows', function (): void {
    $stamp = new WatermarkStamp(210.0, 297.0);

    $label = $stamp->fitLabel(str_repeat('a', 1000), measureStampLabel(), 45.0, 210.0, 99.0);

    expect($label['fontSize'])->toBe(8.0)
        ->and($label['text'])->toEndWith('...')
        ->and(measureStampLabel()($label['text'], 8.0))->toBeLessThanOrEqual($stamp->maxLabelWidthMm(8.0, 45.0, 210.0, 99.0));
});

test('stampable pdf registers a single extgstate for repeated opacities', function (): void {
    $pdf = new StampablePdf;
    $pdf->SetFont('Arial', 'B', 40);

    foreach ([1, 2] as $page) {
        $pdf->AddPage('P', [210, 297]);
        $pdf->rotatedText('DIUQBank.com', 105, 148, 45, 0.05);
    }

    $document = $pdf->Output('S');

    expect($document)->toStartWith('%PDF-1.4')
        ->and(substr_count($document, '/Type /ExtGState'))->toBe(1)
        ->and($document)->toContain('/ca 0.050 /CA 0.050')
        ->and($document)->toContain('/ExtGState <<');
});

/**
 * @return array{text: string, fontSize: float, angleDegrees: float, x: float, y: float, curve: array{amplitudeMm: float, cycles: float, phase: float}}
 */
function stampPlacement(float $amplitudeMm, float $angleDegrees = 30.0, string $text = 'DIUQBank.com'): array
{
    return [
        'text' => $text,
        'fontSize' => 20.0,
        'angleDegrees' => $angleDegrees,
        'x' => 100.0,
        'y' => 150.0,
        'curve' => ['amplitudeMm' => $amplitudeMm, 'cycles' => 1.0, 'phase' => 0.3],
    ];
}

test('stamp curves stay within the configured swing', function (): void {
    $stamp = new WatermarkStamp(210.0, 297.0);

    foreach (range(1, 25) as $seed) {
        foreach ($stamp->placements('DIUQBank.com', measureStampLabel(), seededRandomizer($seed)) as $placement) {
            $labelWidth = measureStampLabel()($placement['text'], $placement['fontSize']);

            expect($placement['curve']['amplitudeMm'])->toBeGreaterThanOrEqual($labelWidth * 0.04 - 0.001)
                ->toBeLessThanOrEqual($labelWidth * 0.12 + 0.001)
                ->and($placement['curve']['cycles'])->toBeGreaterThanOrEqual(0.5)->toBeLessThanOrEqual(1.25);
        }
    }
});

test('a stamp without a curve lays its glyphs on a straight tilted line', function (): void {
    $glyphs = (new WatermarkStamp(210.0, 297.0))->glyphs(stampPlacement(0.0, 30.0), measureStampLabel());
    $direction = deg2rad(30.0);

    expect($glyphs)->toHaveCount(12);

    foreach ($glyphs as $glyph) {
        // Every centre sits on the line through the placement at 30 degrees.
        $offsetX = $glyph['x'] - 100.0;
        $offsetY = $glyph['y'] - 150.0;

        expect($glyph['angleDegrees'])->toEqualWithDelta(30.0, 0.001)
            ->and(($offsetX * sin($direction)) + ($offsetY * cos($direction)))->toEqualWithDelta(0.0, 0.001);
    }
});

test('a curved stamp turns its glyphs along the wave', function (): void {
    $glyphs = (new WatermarkStamp(210.0, 297.0))->glyphs(stampPlacement(2.5), measureStampLabel());
    $angles = array_column($glyphs, 'angleDegrees');

    expect(max($angles) - min($angles))->toBeGreaterThan(20.0);

    // Neighbouring glyphs are 2mm apart along the curve, so the straight line
    // between their centres is at most that, and only a little shorter.
    foreach (array_slice($glyphs, 1, null, true) as $index => $glyph) {
        $previous = $glyphs[$index - 1];

        expect(hypot($glyph['x'] - $previous['x'], $glyph['y'] - $previous['y']))->toBeLessThanOrEqual(2.001)->toBeGreaterThan(1.8);
    }
});

test('curved glyphs stay inside the stamp bounding box', function (): void {
    $stamp = new WatermarkStamp(210.0, 297.0);
    $placement = stampPlacement(5.0, -50.0);
    [$boxWidth, $boxHeight] = $stamp->boundingBox(measureStampLabel()($placement['text'], 20.0), 20.0, -50.0, 5.0);

    foreach ($stamp->glyphs($placement, measureStampLabel()) as $glyph) {
        expect(abs($glyph['x'] - 100.0))->toBeLessThanOrEqual($boxWidth / 2)
            ->and(abs($glyph['y'] - 150.0))->toBeLessThanOrEqual($boxHeight / 2);
    }
});

test('spaces in a stamp advance the text without drawing a glyph', function (): void {
    $glyphs = (new WatermarkStamp(210.0, 297.0))->glyphs(stampPlacement(0.0, 0.0, 'DIU QBank'), measureStampLabel());

    expect(array_column($glyphs, 'text'))->toBe(['D', 'I', 'U', 'Q', 'B', 'a', 'n', 'k'])
        ->and($glyphs[3]['x'] - $glyphs[2]['x'])->toEqualWithDelta(4.0, 0.01);
});
