<?php

use App\Support\PdfCompression\PdfCompressor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/pdf-compressor-'.bin2hex(random_bytes(6));
    mkdir($this->workspace);
});

afterEach(function (): void {
    if (is_dir($this->workspace)) {
        array_map('unlink', glob($this->workspace.'/*') ?: []);
        rmdir($this->workspace);
    }
});

/**
 * Install a stand-in for the Ghostscript binary that records the arguments of
 * every invocation and, optionally, dies from SIGSEGV the way production does.
 *
 * @param  'succeed'|'crash-while-downsampling'|'always-crash'  $behaviour
 */
function fakeGhostscript(string $workspace, string $behaviour = 'succeed'): void
{
    $script = <<<'SHELL'
        #!/bin/sh
        LOG="__LOG__"
        OUT=""
        DOWNSAMPLING=""
        for arg in "$@"; do
            case "$arg" in
                --version) echo "10.02.1"; exit 0 ;;
                -sOutputFile=*) OUT="${arg#-sOutputFile=}" ;;
                -dDownsampleColorImages=true) DOWNSAMPLING="1" ;;
            esac
        done
        echo "$@" >> "$LOG"
        __CRASH__
        printf '%%PDF-1.7 stub' > "$OUT"
        exit 0
        SHELL;

    $crash = match ($behaviour) {
        'crash-while-downsampling' => 'if [ -n "$DOWNSAMPLING" ]; then kill -SEGV $$; fi',
        'always-crash' => 'kill -SEGV $$',
        default => ':',
    };

    $path = $workspace.'/gs';
    file_put_contents($path, str_replace(
        ['__LOG__', '__CRASH__'],
        [$workspace.'/invocations.log', $crash],
        $script,
    ));
    chmod($path, 0755);

    config()->set('pdf.ghostscript.binary', $path);
}

/**
 * @return array<int, string>
 */
function ghostscriptInvocations(string $workspace): array
{
    $log = $workspace.'/invocations.log';

    return file_exists($log) ? array_values(array_filter(explode("\n", trim(file_get_contents($log))))) : [];
}

function makePdf(string $path, float $widthMm, float $heightMm, int $pages = 1): string
{
    $pdf = new Fpdi;
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    for ($page = 1; $page <= $pages; $page++) {
        $pdf->AddPage('P', [$widthMm, $heightMm]);
        $pdf->SetFont('Arial', '', 6);
        $pdf->Cell(0, 5, 'page '.$page);
    }

    $pdf->Output('F', $path);

    return $path;
}

test('image resolution is raised for undersized pages so scans keep their detail', function (): void {
    fakeGhostscript($this->workspace);

    // 210x297 points: the quarter-size page that scanners emit when A4's
    // millimetre numbers are used as points.
    $input = makePdf($this->workspace.'/small.pdf', 210 / 72 * 25.4, 297 / 72 * 25.4);

    app(PdfCompressor::class)->compress($input, $this->workspace.'/out.pdf');

    // 1700px across 210pt (2.917in) needs 583 DPI, not the preset's 150.
    expect(ghostscriptInvocations($this->workspace)[0])
        ->toContain('-dColorImageResolution=583')
        ->toContain('-dGrayImageResolution=583')
        ->toContain('-dColorImageDownsampleThreshold=1.0');
});

test('image resolution stays modest for a normal a4 page', function (): void {
    fakeGhostscript($this->workspace);

    $input = makePdf($this->workspace.'/a4.pdf', 210.0, 297.0);

    app(PdfCompressor::class)->compress($input, $this->workspace.'/out.pdf');

    // 1700px across 210mm (8.268in) is 206 DPI.
    expect(ghostscriptInvocations($this->workspace)[0])->toContain('-dColorImageResolution=206');
});

test('image resolution falls back to the configured default when geometry is unreadable', function (): void {
    fakeGhostscript($this->workspace);

    $input = $this->workspace.'/broken.pdf';
    file_put_contents($input, '%PDF-1.4 not really a pdf');

    app(PdfCompressor::class)->compress($input, $this->workspace.'/out.pdf');

    expect(ghostscriptInvocations($this->workspace)[0])->toContain(sprintf(
        '-dColorImageResolution=%d',
        config('pdf.compression.fallback_image_resolution'),
    ));
});

test('resolution overrides are passed after the preset so they take effect', function (): void {
    fakeGhostscript($this->workspace);

    $input = makePdf($this->workspace.'/a4.pdf', 210.0, 297.0);

    app(PdfCompressor::class)->compress($input, $this->workspace.'/out.pdf');

    $invocation = ghostscriptInvocations($this->workspace)[0];

    expect(strpos($invocation, '-dPDFSETTINGS='))->toBeLessThan(strpos($invocation, '-dColorImageResolution='));
});

test('a ghostscript crash is retried with conservative options', function (): void {
    Log::spy();
    fakeGhostscript($this->workspace, 'crash-while-downsampling');

    $input = makePdf($this->workspace.'/a4.pdf', 210.0, 297.0);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compress($input, $output);

    $invocations = ghostscriptInvocations($this->workspace);

    expect($invocations)->toHaveCount(2)
        ->and($invocations[0])->toContain('-dDownsampleColorImages=true')
        ->and($invocations[1])
        ->toContain('-dDownsampleColorImages=false')
        ->toContain('-dAutoFilterColorImages=false')
        ->toContain('-dColorConversionStrategy=/LeaveColorUnchanged')
        ->and(file_get_contents($output))->toBe('%PDF-1.7 stub');
});

test('a crash on both attempts surfaces a readable error', function (): void {
    fakeGhostscript($this->workspace, 'always-crash');

    $input = makePdf($this->workspace.'/a4.pdf', 210.0, 297.0);

    expect(fn () => app(PdfCompressor::class)->compress($input, $this->workspace.'/out.pdf'))
        ->toThrow(RuntimeException::class, 'crashed with signal 11');
});

test('page extraction rejects out of range pages without repairing the document', function (): void {
    fakeGhostscript($this->workspace);

    $input = makePdf($this->workspace.'/a4.pdf', 210.0, 297.0, pages: 2);

    expect(fn () => app(PdfCompressor::class)->extractPages($input, $this->workspace.'/out.pdf', [5]))
        ->toThrow(RuntimeException::class, 'out of range');

    expect(ghostscriptInvocations($this->workspace))->toBeEmpty();
});

test('watermarking a small page produces a proportional header and a fitted label', function (): void {
    $input = makePdf($this->workspace.'/small.pdf', 210 / 72 * 25.4, 297 / 72 * 25.4);
    $output = $this->workspace.'/out.pdf';

    (new PdfCompressor)->compressWithWatermark($input, $output, str_repeat('DIUQBank.com | ', 4));

    $reader = new PdfReader(new PdfParser(
        StreamReader::createByFile($output)
    ));
    [$width, $height] = $reader->getPage(1)->getWidthAndHeight();

    // A 4mm header on a 74mm wide page, rather than the 8mm A4 banner.
    expect($width)->toEqualWithDelta(210.0, 0.5)
        ->and($height - 297.0)->toEqualWithDelta(4 / 25.4 * 72, 0.5);
})->skip(fn (): bool => ! ghostscriptIsInstalled(), 'Ghostscript is not installed.');

function ghostscriptIsInstalled(): bool
{
    return Process::run(['gs', '--version'])->successful();
}
