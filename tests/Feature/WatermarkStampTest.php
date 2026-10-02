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

test('a single stamp is placed on the page by default', function (): void {
    $placements = (new WatermarkStamp(210.0, 297.0))->placements('DIUQBank.com', measureStampLabel(), seededRandomizer());

    expect($placements)->toHaveCount(1);
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
