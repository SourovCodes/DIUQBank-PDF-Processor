<?php

use App\Support\PdfCompression\WatermarkHeader;

/**
 * Measures a label as though each character were exactly 1mm wide per point of
 * font size, which keeps the expectations below independent of font metrics.
 */
function measureLabel(): Closure
{
    return fn (string $text, float $fontSize): float => mb_strlen($text) * $fontSize * 0.1;
}

test('header geometry on a4 matches the original fixed dimensions', function (): void {
    $header = new WatermarkHeader(210.0);

    expect($header->heightMm)->toBe(8.0)
        ->and($header->sidePaddingMm)->toBe(2.0)
        ->and($header->nominalFontSize)->toBe(8.0);
});

test('header shrinks with the page instead of staying at the a4 size', function (): void {
    $header = new WatermarkHeader(74.083);

    expect($header->heightMm)
        ->toBeLessThan(8.0)
        ->toBe(4.0)
        ->and($header->sidePaddingMm)->toBe(1.0);
});

test('header height is capped on oversized pages', function (): void {
    $header = new WatermarkHeader(841.0);

    expect($header->heightMm)->toBe(12.0);
});

test('header font size never drops below the configured minimum', function (): void {
    $header = new WatermarkHeader(20.0);

    expect($header->nominalFontSize)->toBe(5.0);
});

test('label keeps the nominal font size when it already fits', function (): void {
    $header = new WatermarkHeader(210.0);

    $label = $header->fitLabel('DIUQBank.com', measureLabel());

    expect($label['fontSize'])->toBe(8.0)
        ->and($label['text'])->toBe('DIUQBank.com');
});

test('label font size shrinks until the label fits the page', function (): void {
    $header = new WatermarkHeader(210.0);
    $text = str_repeat('a', 300);

    $label = $header->fitLabel($text, measureLabel());

    expect($label['fontSize'])->toBeLessThan(8.0)
        ->and($label['text'])->toBe($text)
        ->and(measureLabel()($label['text'], $label['fontSize']))->toBeLessThanOrEqual($header->textWidthMm());
});

test('label is truncated when even the smallest font size overflows', function (): void {
    $header = new WatermarkHeader(210.0);
    $text = str_repeat('a', 2000);

    $label = $header->fitLabel($text, measureLabel());

    expect($label['fontSize'])->toBe(5.0)
        ->and($label['text'])->toEndWith('...')
        ->and(mb_strlen($label['text']))->toBeLessThan(2000)
        ->and(measureLabel()($label['text'], $label['fontSize']))->toBeLessThanOrEqual($header->textWidthMm());
});
