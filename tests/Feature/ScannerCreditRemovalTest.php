<?php

use App\Support\PdfCompression\PageLayout;
use App\Support\PdfCompression\PageRegion;
use App\Support\PdfCompression\PdfCompressor;
use App\Support\PdfCompression\PdfWhitespaceDetector;
use App\Support\PdfCompression\ScannerCreditDetector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/pdf-credit-test-'.bin2hex(random_bytes(6));
    mkdir($this->workspace);
    config()->set('pdf.api_key', 'test-api-key');
});

afterEach(function (): void {
    if (is_dir($this->workspace)) {
        array_map('unlink', glob($this->workspace.'/*') ?: []);
        rmdir($this->workspace);
    }
});

function creditTestGhostscriptIsInstalled(): bool
{
    return Process::run(['gs', '--version'])->successful();
}

/**
 * An A4 page laid out like a CamScanner export: the scan fills most of the page
 * and a dark credit sits in the bottom-right corner under a link to the app.
 */
function makeScannedPdf(string $path, ?string $creditUri = 'https://v3.camscanner.com/user/download'): string
{
    $pdf = new Fpdi;
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage('P', [210.0, 297.0]);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->Rect(10.0, 10.0, 190.0, 250.0, 'F');
    $pdf->Rect(175.0, 284.0, 30.0, 8.0, 'F');

    if ($creditUri !== null) {
        $pdf->Link(171.0, 281.0, 39.0, 16.0, $creditUri);
    }

    $pdf->Output('F', $path);

    return $path;
}

/**
 * Share of dark pixels inside a region of the rendered first page.
 */
function darkShareIn(string $pdfPath, PageRegion $region): float
{
    $bitmapPath = $pdfPath.'.pgm';
    Process::run(['gs', '-sDEVICE=pgmraw', '-dNOPAUSE', '-dBATCH', '-dQUIET', '-dSAFER', '-r36', '-dFirstPage=1', '-dLastPage=1', '-sOutputFile='.$bitmapPath, $pdfPath])->throw();

    $bitmap = (string) file_get_contents($bitmapPath);
    unlink($bitmapPath);
    preg_match('/\AP5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+(\d+)\s/', $bitmap, $header);
    [$width, $height] = [(int) $header[1], (int) $header[2]];
    $pixels = substr($bitmap, strlen($header[0]));

    $dark = 0;
    $total = 0;

    for ($y = (int) ceil($region->top * $height); $y < (int) floor($region->bottom * $height); $y++) {
        for ($x = (int) ceil($region->left * $width); $x < (int) floor($region->right * $width); $x++) {
            $total++;
            $dark += ord($pixels[$y * $width + $x]) < 128 ? 1 : 0;
        }
    }

    return $total === 0 ? 0.0 : $dark / $total;
}

test('a camscanner link marks the credit region', function (): void {
    $regions = (new ScannerCreditDetector)->creditRegionsFor(makeScannedPdf($this->workspace.'/in.pdf'));

    expect($regions)->toHaveKey(1)
        ->and($regions[1])->toHaveCount(1)
        ->and($regions[1][0]->left)->toEqualWithDelta(171 / 210, 0.001)
        ->and($regions[1][0]->top)->toEqualWithDelta(281 / 297, 0.001)
        ->and($regions[1][0]->right)->toEqualWithDelta(1.0, 0.001)
        ->and($regions[1][0]->bottom)->toEqualWithDelta(1.0, 0.001);
});

test('links to other sites and pages without links are not credits', function (): void {
    $detector = new ScannerCreditDetector;

    expect($detector->creditRegionsFor(makeScannedPdf($this->workspace.'/other.pdf', 'https://diuqbank.com/camscanner.com')))->toBe([])
        ->and($detector->creditRegionsFor(makeScannedPdf($this->workspace.'/plain.pdf', null)))->toBe([])
        ->and($detector->creditRegionsFor($this->workspace.'/missing.pdf'))->toBe([]);
});

test('subdomains of a credit domain count', function (): void {
    $regions = (new ScannerCreditDetector)->creditRegionsFor(makeScannedPdf($this->workspace.'/in.pdf', 'https://www.camscanner.com/'));

    expect($regions)->toHaveKey(1);
});

test('whitespace detection ignores hidden regions', function (): void {
    config()->set('pdf.trim.padding_points', 0.0);
    config()->set('pdf.trim.min_content_pixels', 1);

    $pixels = str_repeat("\xFF", 100 * 100);

    foreach ([[10, 10], [11, 11], [95, 95], [96, 96]] as [$x, $y]) {
        $pixels[$y * 100 + $x] = "\x00";
    }

    $bitmap = "P5\n100 100\n255\n".$pixels;
    $crop = (new PdfWhitespaceDetector)->cropForBitmap($bitmap, [new PageRegion(0.9, 0.9, 1.0, 1.0)]);

    expect($crop->right)->toEqualWithDelta(0.12, 0.0001)
        ->and($crop->bottom)->toEqualWithDelta(0.12, 0.0001);
});

test('the credit is blanked out while the scan is kept', function (): void {
    $input = makeScannedPdf($this->workspace.'/in.pdf');
    $output = $this->workspace.'/out.pdf';
    $credit = new PageRegion(176 / 210, 285 / 297, 204 / 210, 291 / 297);
    $scan = new PageRegion(0.1, 0.1, 0.9, 0.8);

    app(PdfCompressor::class)->compress($input, $output, new PageLayout(removeScannerCredits: true));

    expect(darkShareIn($input, $credit))->toBeGreaterThan(0.9)
        ->and(darkShareIn($output, $credit))->toBe(0.0)
        ->and(darkShareIn($output, $scan))->toBeGreaterThan(0.9);
})->skip(fn (): bool => ! creditTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('the credit is kept when removal is off', function (): void {
    $input = makeScannedPdf($this->workspace.'/in.pdf');
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compress($input, $output, new PageLayout);

    expect(darkShareIn($output, new PageRegion(176 / 210, 285 / 297, 204 / 210, 291 / 297)))->toBeGreaterThan(0.9);
})->skip(fn (): bool => ! creditTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('trimming crops away the corner the credit sat in', function (): void {
    config()->set('pdf.trim.padding_points', 0.0);

    $input = makeScannedPdf($this->workspace.'/in.pdf');
    $output = $this->workspace.'/out.pdf';

    app(PdfCompressor::class)->compressWithCreditAndWatermark(
        $input,
        $output,
        'Uploaded by Sourov',
        'DIUQBank.com',
        new PageLayout(trimWhitespace: true, removeScannerCredits: true),
    );

    [$width, $height] = (new PdfReader(new PdfParser(StreamReader::createByFile($output))))->getPage(1)->getWidthAndHeight();
    $headerHeight = 8 / 210 * 190 / 25.4 * 72;

    // Only the 190x250mm scan is left, under its header.
    expect((float) $width)->toEqualWithDelta(190 / 25.4 * 72, 3.0)
        ->and((float) $height)->toEqualWithDelta(250 / 25.4 * 72 + $headerHeight, 3.0);
})->skip(fn (): bool => ! creditTestGhostscriptIsInstalled(), 'Ghostscript is not installed.');

test('scanner credits are removed by default', function (): void {
    config()->set('pdf.scanner_credit.remove', true);

    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('compress')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, PageLayout $layout): void {
                expect($layout->removeScannerCredits)->toBeTrue();

                file_put_contents($outputPath, '%PDF-1.4 compressed');
            });
    });

    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
        ])
        ->assertOk();
});

test('scanner credit removal can be turned off per request', function (): void {
    config()->set('pdf.scanner_credit.remove', true);

    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('compressWithWatermark')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, string $watermarkText, PageLayout $layout): void {
                expect($layout->removeScannerCredits)->toBeFalse();

                file_put_contents($outputPath, '%PDF-1.4 watermarked');
            });
    });

    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'watermark_text' => 'DIUQBank.com',
            'remove_scanner_credit' => '0',
        ])
        ->assertOk();
});

test('remove scanner credit must be a boolean', function (): void {
    $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/credit-watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'credit_text' => 'Uploaded by Sourov',
            'watermark_text' => 'DIUQBank.com',
            'remove_scanner_credit' => 'maybe',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['remove_scanner_credit']);
});
