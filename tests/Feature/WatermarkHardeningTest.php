<?php

use App\Support\PdfCompression\PageLayout;
use App\Support\PdfCompression\PdfCompressor;
use App\Support\PdfCompression\PdfPageRasterizer;
use App\Support\PdfCompression\PdfTextAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use setasign\Fpdi\Fpdi;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/pdf-hardening-test-'.bin2hex(random_bytes(6));
    mkdir($this->workspace);
    config()->set('pdf.api_key', 'test-api-key');
});

afterEach(function (): void {
    if (is_dir($this->workspace)) {
        array_map('unlink', glob($this->workspace.'/*') ?: []);
        rmdir($this->workspace);
    }
});

function hardeningTestGhostscriptIsInstalled(): bool
{
    return Process::run(['gs', '--version'])->successful();
}

/**
 * An A4 page per entry: `null` gives a scan-like page (a grey block, no text),
 * a string gives a page of real text.
 *
 * @param  array<int, string|null>  $pages
 */
function makeHardeningPdf(string $path, array $pages): string
{
    $pdf = new Fpdi;
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    foreach ($pages as $text) {
        $pdf->AddPage('P', [210.0, 297.0]);

        if ($text === null) {
            $pdf->SetFillColor(200, 200, 200);
            $pdf->Rect(20.0, 20.0, 170.0, 200.0, 'F');
        } else {
            $pdf->SetFont('Arial', '', 12);
            $pdf->SetXY(20.0, 20.0);
            $pdf->MultiCell(170.0, 6.0, $text);
        }
    }

    $pdf->Output('F', $path);

    return $path;
}

function extractedText(string $pdfPath): string
{
    return Process::run(['gs', '-sDEVICE=txtwrite', '-dNOPAUSE', '-dBATCH', '-dQUIET', '-sOutputFile=-', $pdfPath])->throw()->output();
}

/**
 * Lowest grey level found in a band of the rendered first page, given as
 * fractions of the page height from the top. 255 means the band is blank.
 */
function darkestGreyIn(string $pdfPath, float $top, float $bottom): int
{
    $bitmapPath = $pdfPath.'.pgm';
    Process::run(['gs', '-sDEVICE=pgmraw', '-dNOPAUSE', '-dBATCH', '-dQUIET', '-r72', '-dFirstPage=1', '-dLastPage=1', '-sOutputFile='.$bitmapPath, $pdfPath])->throw();

    $bitmap = (string) file_get_contents($bitmapPath);
    unlink($bitmapPath);
    preg_match('/\AP5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+(\d+)\s/', $bitmap, $header);
    [$width, $height] = [(int) $header[1], (int) $header[2]];
    $pixels = substr($bitmap, strlen($header[0]));
    $band = substr($pixels, (int) floor($top * $height) * $width, ((int) ceil($bottom * $height) - (int) floor($top * $height)) * $width);

    return min(array_map('ord', str_split($band)));
}

function stripText(string $pdfPath, string $outputPath): string
{
    Process::run(['gs', '-sDEVICE=pdfwrite', '-dNOPAUSE', '-dBATCH', '-dQUIET', '-dFILTERTEXT', '-sOutputFile='.$outputPath, $pdfPath])->throw();

    return $outputPath;
}

/**
 * Whether a raw string turns up in the file, inside compressed streams included.
 */
function pdfContains(string $pdfPath, string $needle): bool
{
    $contents = (string) file_get_contents($pdfPath);

    if (str_contains($contents, $needle)) {
        return true;
    }

    preg_match_all('/stream\r?\n(.*?)endstream/s', $contents, $streams);

    foreach ($streams[1] as $stream) {
        $inflated = @gzuncompress($stream);

        if ($inflated !== false && str_contains($inflated, $needle)) {
            return true;
        }
    }

    return false;
}

test('the text audit tells scanned pages from text pages', function (): void {
    $input = makeHardeningPdf($this->workspace.'/mixed.pdf', [null, str_repeat('Question one asks about process scheduling. ', 3), 'CamScanner']);

    expect((new PdfTextAudit)->scannedPages($input))->toBe([1, 3]);
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('the rasterizer keys rendered pages by their page number', function (): void {
    $input = makeHardeningPdf($this->workspace.'/three.pdf', [null, null, null]);

    $images = (new PdfPageRasterizer)->renderToJpeg($input, [3, 2], 36, $this->workspace);

    expect(array_keys($images))->toBe([2, 3])
        ->and(getimagesize($images[3])['mime'])->toBe('image/jpeg');
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('watermark text is outlined so text tools cannot find it', function (): void {
    $input = makeHardeningPdf($this->workspace.'/text.pdf', [str_repeat('Explain the process state diagram. ', 3)]);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com');

    $text = extractedText($output);

    expect($text)->toContain('Explain the process state diagram')
        ->not->toContain('Uploaded by Sourov')
        ->not->toContain('DIUQBank.com');
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('marks on a scanned page survive text filtering', function (): void {
    config()->set('pdf.hardening.bake_scanned_pages', true);
    config()->set('pdf.hardening.outline_marks', false);

    $input = makeHardeningPdf($this->workspace.'/scan.pdf', [null]);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com');

    $stripped = stripText($output, $this->workspace.'/stripped.pdf');

    // The header band (top 3% of the page) still holds dark credit text.
    expect(darkestGreyIn($stripped, 0.0, 0.03))->toBeLessThan(128)
        ->and(extractedText($output))->not->toContain('Uploaded by Sourov');
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('without hardening the marks are stripped by text filtering', function (): void {
    config()->set('pdf.hardening.bake_scanned_pages', false);
    config()->set('pdf.hardening.outline_marks', false);

    $input = makeHardeningPdf($this->workspace.'/scan.pdf', [null]);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com');

    // Only the header's light separator line is left.
    expect(darkestGreyIn(stripText($output, $this->workspace.'/stripped.pdf'), 0.0, 0.03))->toBeGreaterThan(200);
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('pages with real text are not flattened', function (): void {
    config()->set('pdf.hardening.bake_scanned_pages', true);

    $input = makeHardeningPdf($this->workspace.'/text.pdf', [str_repeat('Explain the process state diagram. ', 3)]);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com');

    expect(extractedText($output))->toContain('Explain the process state diagram');
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('the trace id is hidden in the page text and the document info', function (): void {
    $input = makeHardeningPdf($this->workspace.'/scan.pdf', [null]);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com', traceId: 'upload-42');

    expect(extractedText($output))->toContain('DIUQBank trace upload-42')
        ->and(pdfContains($output, '/DIUQBankTraceId'))->toBeTrue()
        // A clean render shows nothing of the trace text.
        ->and(darkestGreyIn($output, 0.97, 1.0))->toBeGreaterThan(200);
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('an unsafe trace id is rejected', function (): void {
    $input = makeHardeningPdf($this->workspace.'/scan.pdf', [null]);

    app(PdfCompressor::class)->compressWithWatermark($input, $this->workspace.'/out.pdf', 'Uploaded by Sourov', traceId: 'x) /Evil (y');
})->throws(RuntimeException::class, 'trace ID')
    ->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('the result is locked against editing but still opens', function (): void {
    config()->set('pdf.hardening.lock_editing', true);

    $input = makeHardeningPdf($this->workspace.'/scan.pdf', [null]);
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com');

    $contents = (string) file_get_contents($output);

    expect($contents)->toContain('/Encrypt')
        ->toContain('/P -1852')
        ->and(darkestGreyIn($output, 0.0, 0.03))->toBeLessThan(128);
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('watermark endpoints return a generated trace id', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('compressWithWatermark')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, string $watermarkText, PageLayout $layout, ?string $traceId): void {
                expect($traceId)->toHaveLength(26);

                file_put_contents($outputPath, '%PDF-1.4 watermarked');
            });
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'watermark_text' => 'DIUQBank.com',
        ])
        ->assertOk();

    expect($response->headers->get('X-PDF-Trace-Id'))->toHaveLength(26);
});

test('watermark endpoints use and return the caller trace id', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('compressWithCreditAndWatermark')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, string $creditText, string $watermarkText, PageLayout $layout, ?string $traceId): void {
                expect($traceId)->toBe('question-1234');

                file_put_contents($outputPath, '%PDF-1.4 watermarked');
            });
    });

    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/credit-watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'credit_text' => 'Uploaded by Sourov',
            'watermark_text' => 'DIUQBank.com',
            'trace_id' => 'question-1234',
        ])
        ->assertOk()
        ->assertHeader('X-PDF-Trace-Id', 'question-1234');
});

test('trace ids with unsafe characters are refused', function (): void {
    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'watermark_text' => 'DIUQBank.com',
            'trace_id' => 'x) /Evil (y',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['trace_id']);
});

/**
 * Brightest grey level inside the page content area, header excluded.
 */
function lightestGreyInContent(string $pdfPath): int
{
    $bitmapPath = $pdfPath.'.pgm';
    Process::run(['gs', '-sDEVICE=pgmraw', '-dNOPAUSE', '-dBATCH', '-dQUIET', '-r36', '-sOutputFile='.$bitmapPath, $pdfPath])->throw();

    $bitmap = (string) file_get_contents($bitmapPath);
    unlink($bitmapPath);
    preg_match('/\AP5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+(\d+)\s/', $bitmap, $header);
    [$width, $height] = [(int) $header[1], (int) $header[2]];
    $pixels = substr($bitmap, strlen($header[0]));

    // Skip the header band and the page margins.
    $lightest = 0;

    for ($y = (int) ($height * 0.1); $y < (int) ($height * 0.9); $y++) {
        for ($x = (int) ($width * 0.1); $x < (int) ($width * 0.9); $x++) {
            $lightest = max($lightest, ord($pixels[$y * $width + $x]));
        }
    }

    return $lightest;
}

dataset('stamp blend modes', [
    'multiply keeps black content black' => ['Multiply', 10, 'toBeLessThan'],
    'normal would lighten it' => ['Normal', 30, 'toBeGreaterThan'],
]);

test('stamps blend into the page', function (string $blendMode, int $threshold, string $expectation): void {
    config()->set('pdf.watermark.stamp.opacity', 0.6);
    config()->set('pdf.watermark.stamp.colour', '#FF0000');
    config()->set('pdf.watermark.stamp.blend_mode', $blendMode);

    $pdf = new Fpdi;
    $pdf->AddPage('P', [210.0, 297.0]);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->Rect(0.0, 0.0, 210.0, 297.0, 'F');
    $input = $this->workspace.'/black.pdf';
    $pdf->Output('F', $input);

    $output = $this->workspace.'/out.pdf';
    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com');

    expect(lightestGreyInContent($output))->{$expectation}($threshold);
})->with('stamp blend modes')
    ->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('inspect requires an api key and a pdf', function (): void {
    $this->post('/api/pdfs/inspect')->assertUnauthorized();

    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/inspect')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});

test('inspect reads the trace id back from a locked, flattened pdf', function (): void {
    config()->set('pdf.hardening.bake_scanned_pages', true);
    config()->set('pdf.hardening.lock_editing', true);

    $input = makeHardeningPdf($this->workspace.'/scan.pdf', [null, null]);
    $output = $this->workspace.'/out.pdf';
    app(PdfCompressor::class)->compressWithCreditAndWatermark($input, $output, 'Uploaded by Sourov', 'DIUQBank.com', traceId: 'question-1234');

    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/inspect', [
            'pdf' => new UploadedFile($output, 'reposted.pdf', 'application/pdf', null, true),
        ])
        ->assertOk()
        ->assertExactJson(['traced' => true, 'trace_ids' => ['question-1234']]);
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('inspect reports an untraced pdf', function (): void {
    $input = makeHardeningPdf($this->workspace.'/plain.pdf', ['Not from DIUQBank']);

    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/inspect', [
            'pdf' => new UploadedFile($input, 'plain.pdf', 'application/pdf', null, true),
        ])
        ->assertOk()
        ->assertExactJson(['traced' => false, 'trace_ids' => []]);
})->skip(fn (): bool => ! hardeningTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');
