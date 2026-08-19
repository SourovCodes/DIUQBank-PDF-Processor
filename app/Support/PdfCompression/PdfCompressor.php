<?php

namespace App\Support\PdfCompression;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

class PdfCompressor
{
    public function __construct(private readonly PdfPageGeometry $geometry = new PdfPageGeometry) {}

    public function compress(string $inputPath, string $outputPath): void
    {
        $this->ensureGhostscriptAvailable();
        $this->ensureFileExists($inputPath);

        $this->runGhostscript($inputPath, $outputPath, $this->compressionArguments($inputPath));
    }

    /**
     * @param  array<int, int>  $pages  1-indexed page numbers in desired output order, already deduped
     */
    public function extractPages(string $inputPath, string $outputPath, array $pages): void
    {
        $this->ensureFileExists($inputPath);

        if ($pages === []) {
            throw new RuntimeException('At least one page must be requested.');
        }

        $this->withRepairFallback(
            $inputPath,
            fn (string $sourcePath) => $this->extractPagesWithFpdi($sourcePath, $outputPath, $pages),
            'page extraction',
        );
    }

    public function compressWithWatermark(string $inputPath, string $outputPath, string $watermarkText): void
    {
        $this->ensureGhostscriptAvailable();
        $this->ensureFileExists($inputPath);

        $watermarkedPath = $this->temporaryPdfPath('pdf-watermark-');

        try {
            $this->withRepairFallback(
                $inputPath,
                fn (string $sourcePath) => $this->addWatermarkWithFpdi($sourcePath, $watermarkedPath, $watermarkText),
                'watermarking',
            );

            /*
             * Resolution is derived from the original document: the repaired copy
             * that FPDI may have consumed is already downsampling-free, but its
             * page geometry is identical, so either input yields the same target.
             */
            $this->runGhostscript($watermarkedPath, $outputPath, $this->compressionArguments($inputPath));
        } finally {
            @unlink($watermarkedPath);
        }
    }

    /**
     * Run an FPDI operation, retrying once against a Ghostscript-repaired copy.
     *
     * FPDI aborts on damaged cross-reference entries that Ghostscript rebuilds
     * without complaint. The repair pass deliberately performs no downsampling so
     * documents never pay for two lossy compression passes.
     *
     * @param  callable(string): void  $operation
     */
    private function withRepairFallback(string $inputPath, callable $operation, string $description): void
    {
        try {
            $operation($inputPath);

            return;
        } catch (RuntimeException $exception) {
            /*
             * Our own validation failures (an out-of-range page, a missing file)
             * are not parse errors, so repairing the document cannot help.
             */
            throw $exception;
        } catch (Throwable $exception) {
            $this->ensureGhostscriptAvailable();

            Log::warning('FPDI '.$description.' failed; retrying against a Ghostscript-repaired copy.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        $repairedPath = $this->temporaryPdfPath('pdf-repaired-');

        try {
            $this->runGhostscript($inputPath, $repairedPath, $this->repairArguments());
            $operation($repairedPath);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'FPDI '.$description.' failed after Ghostscript repair: '.$exception->getMessage(),
                0,
                $exception,
            );
        } finally {
            @unlink($repairedPath);
        }
    }

    /**
     * @param  array<int, string>  $arguments  Ghostscript options, excluding binary and file paths
     */
    private function runGhostscript(string $inputPath, string $outputPath, array $arguments): void
    {
        try {
            $this->executeGhostscript($inputPath, $outputPath, $arguments);

            return;
        } catch (GhostscriptCrashed $crash) {
            Log::warning('Ghostscript crashed; retrying with conservative options.', [
                'signal' => $crash->signal,
            ]);
        }

        /*
         * Ghostscript segfaults on some malformed images (16-bit samples and odd
         * ICC profiles are the usual culprits). The retry avoids the downsampling,
         * auto-filtering and colour-management code paths entirely, which costs
         * output size but keeps the request alive.
         */
        try {
            $this->executeGhostscript($inputPath, $outputPath, $this->crashSafeArguments());
        } catch (GhostscriptCrashed $crash) {
            throw new RuntimeException(
                'Ghostscript could not process this PDF; it crashed with signal '.$crash->signal.'.',
                0,
                $crash,
            );
        }
    }

    /**
     * @param  array<int, string>  $arguments
     *
     * @throws GhostscriptCrashed
     */
    private function executeGhostscript(string $inputPath, string $outputPath, array $arguments): void
    {
        try {
            $result = Process::path(base_path())
                ->timeout((int) config('pdf.ghostscript.timeout'))
                ->run([
                    (string) config('pdf.ghostscript.binary'),
                    ...$arguments,
                    '-sOutputFile='.$outputPath,
                    $inputPath,
                ]);
        } catch (ProcessSignaledException $exception) {
            throw new GhostscriptCrashed($exception->getSignal(), $exception);
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException(
                'Ghostscript timed out after '.config('pdf.ghostscript.timeout').' seconds.',
                0,
                $exception,
            );
        }

        if ($result->failed()) {
            throw new RuntimeException(
                trim($result->errorOutput()) !== ''
                    ? 'Ghostscript failed: '.trim($result->errorOutput())
                    : 'Ghostscript failed to process the PDF.'
            );
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('Ghostscript did not produce a PDF file.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function baseArguments(): array
    {
        return [
            '-sDEVICE=pdfwrite',
            '-dNOPAUSE',
            '-dBATCH',
            '-dQUIET',
            '-dSAFER',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function compressionArguments(string $inputPath): array
    {
        $resolution = $this->imageResolutionFor($inputPath);
        $monoResolution = $this->monoImageResolutionFor($resolution);

        /*
         * -dPDFSETTINGS installs a whole family of defaults, so the explicit
         * resolution options must follow it to take effect. The downsample
         * thresholds are pinned to 1.0 because the preset's default of 1.5 lets
         * images up to 50% above the target through untouched.
         */
        return [
            ...$this->baseArguments(),
            '-dCompatibilityLevel=1.4',
            sprintf('-dPDFSETTINGS=/%s', (string) config('pdf.ghostscript.preset')),
            '-dDownsampleColorImages=true',
            '-dColorImageDownsampleType=/Bicubic',
            sprintf('-dColorImageResolution=%d', $resolution),
            '-dColorImageDownsampleThreshold=1.0',
            '-dDownsampleGrayImages=true',
            '-dGrayImageDownsampleType=/Bicubic',
            sprintf('-dGrayImageResolution=%d', $resolution),
            '-dGrayImageDownsampleThreshold=1.0',
            '-dDownsampleMonoImages=true',
            '-dMonoImageDownsampleType=/Subsample',
            sprintf('-dMonoImageResolution=%d', $monoResolution),
            '-dMonoImageDownsampleThreshold=1.0',
        ];
    }

    /**
     * Rebuild a damaged document without resampling anything.
     *
     * @return array<int, string>
     */
    private function repairArguments(): array
    {
        return [
            ...$this->baseArguments(),
            '-dCompatibilityLevel=1.4',
            '-dAutoRotatePages=/None',
            '-dDownsampleColorImages=false',
            '-dDownsampleGrayImages=false',
            '-dDownsampleMonoImages=false',
            '-dColorConversionStrategy=/LeaveColorUnchanged',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function crashSafeArguments(): array
    {
        return [
            ...$this->baseArguments(),
            '-dCompatibilityLevel=1.7',
            '-dAutoRotatePages=/None',
            '-dDownsampleColorImages=false',
            '-dDownsampleGrayImages=false',
            '-dDownsampleMonoImages=false',
            '-dAutoFilterColorImages=false',
            '-dAutoFilterGrayImages=false',
            '-dColorConversionStrategy=/LeaveColorUnchanged',
        ];
    }

    /**
     * Derive a DPI cap from the narrowest page so that undersized pages keep the
     * same pixel budget as a normal A4 page.
     */
    private function imageResolutionFor(string $inputPath): int
    {
        $widthInPoints = $this->geometry->narrowestPageWidthInPoints($inputPath);

        if ($widthInPoints === null) {
            return (int) config('pdf.compression.fallback_image_resolution');
        }

        $widthInInches = $widthInPoints / 72;
        $resolution = (int) ceil((int) config('pdf.compression.target_pixel_width') / $widthInInches);

        return $this->clampInt(
            $resolution,
            (int) config('pdf.compression.min_image_resolution'),
            (int) config('pdf.compression.max_image_resolution'),
        );
    }

    private function monoImageResolutionFor(int $resolution): int
    {
        return $this->clampInt(
            $resolution * (int) config('pdf.compression.mono_pixel_width_multiplier'),
            (int) config('pdf.compression.min_mono_image_resolution'),
            (int) config('pdf.compression.max_mono_image_resolution'),
        );
    }

    /**
     * @param  array<int, int>  $pages
     */
    private function extractPagesWithFpdi(string $inputPath, string $outputPath, array $pages): void
    {
        $pdf = $this->newDocument();
        $sourcePageCount = $pdf->setSourceFile($inputPath);

        foreach ($pages as $page) {
            if ($page < 1 || $page > $sourcePageCount) {
                throw new RuntimeException(sprintf(
                    'Requested page %d is out of range (document has %d pages).',
                    $page,
                    $sourcePageCount,
                ));
            }
        }

        foreach ($pages as $page) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);

            $pageWidth = (float) $size['width'];
            $pageHeight = (float) $size['height'];

            $pdf->AddPage($pageWidth > $pageHeight ? 'L' : 'P', [$pageWidth, $pageHeight]);
            $pdf->useTemplate($templateId, 0, 0, $pageWidth, $pageHeight);
        }

        $pdf->Output('F', $outputPath);
    }

    private function addWatermarkWithFpdi(string $inputPath, string $outputPath, string $watermarkText): void
    {
        $pdf = $this->newDocument();
        $pageCount = $pdf->setSourceFile($inputPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);

            $pageWidth = (float) $size['width'];
            $pageHeight = (float) $size['height'];

            $header = new WatermarkHeader($pageWidth);
            $totalHeight = $pageHeight + $header->heightMm;

            $pdf->AddPage($pageWidth > $totalHeight ? 'L' : 'P', [$pageWidth, $totalHeight]);
            $this->drawWatermarkHeader($pdf, $header, $watermarkText);
            $pdf->useTemplate($templateId, 0, $header->heightMm, $pageWidth, $pageHeight);
        }

        $pdf->Output('F', $outputPath);
    }

    private function drawWatermarkHeader(Fpdi $pdf, WatermarkHeader $header, string $text): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, $header->pageWidthMm, $header->heightMm, 'F');

        $pdf->SetDrawColor(210, 210, 210);
        $pdf->Line(0, $header->heightMm, $header->pageWidthMm, $header->heightMm);

        $label = $header->fitLabel(
            $this->toCoreFontEncoding($text),
            function (string $candidate, float $fontSize) use ($pdf): float {
                $pdf->SetFont('Arial', '', $fontSize);

                return $pdf->GetStringWidth($candidate);
            },
        );

        $pdf->SetFont('Arial', '', $label['fontSize']);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($header->sidePaddingMm, $header->heightMm * 0.25);
        $pdf->Cell($header->textWidthMm(), $header->heightMm * 0.5, $label['text'], 0, 0, 'L');
    }

    /**
     * FPDF's built-in fonts only cover Windows-1252, so anything outside it is
     * transliterated (or dropped) instead of being written as mojibake.
     */
    private function toCoreFontEncoding(string $text): string
    {
        if (mb_check_encoding($text, 'Windows-1252')) {
            return $text;
        }

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $text) ?? '' : $converted;
    }

    private function newDocument(): Fpdi
    {
        $pdf = new Fpdi;
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        return $pdf;
    }

    private function ensureGhostscriptAvailable(): void
    {
        $binary = (string) config('pdf.ghostscript.binary');
        $result = Process::run([$binary, '--version']);

        if ($result->failed()) {
            throw new RuntimeException(sprintf('Ghostscript binary [%s] is not installed or not on PATH.', $binary));
        }
    }

    private function ensureFileExists(string $path): void
    {
        if (! file_exists($path)) {
            throw new RuntimeException('Input PDF file not found: '.$path);
        }
    }

    private function clampInt(int $value, int $minimum, int $maximum): int
    {
        return min(max($value, $minimum), $maximum);
    }

    private function temporaryPdfPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary PDF path.');
        }

        @unlink($path);

        return $path.'.pdf';
    }
}
