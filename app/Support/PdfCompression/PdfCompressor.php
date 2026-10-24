<?php

namespace App\Support\PdfCompression;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Random\Randomizer;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

class PdfCompressor
{
    public function __construct(
        private readonly PdfPageGeometry $geometry = new PdfPageGeometry,
        private readonly PdfImageAudit $imageAudit = new PdfImageAudit,
        private readonly PdfWhitespaceDetector $whitespaceDetector = new PdfWhitespaceDetector,
        private readonly ScannerCreditDetector $scannerCreditDetector = new ScannerCreditDetector,
        private readonly PdfTextAudit $textAudit = new PdfTextAudit,
        private readonly PdfPageRasterizer $rasterizer = new PdfPageRasterizer,
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    public function compress(string $inputPath, string $outputPath, PageLayout $layout = new PageLayout): void
    {
        $this->ensureGhostscriptAvailable();
        $this->ensureFileExists($inputPath);

        $credits = $layout->removeScannerCredits ? $this->scannerCreditDetector->creditRegionsFor($inputPath) : [];
        $crops = $layout->trimWhitespace ? $this->whitespaceDetector->cropsFor($inputPath, $credits) : [];

        if ($crops === [] && $credits === [] && $this->alreadyFitsLayout($inputPath, $layout)) {
            $this->runGhostscript($inputPath, $outputPath, $this->compressionArguments($inputPath));

            return;
        }

        $laidOutPath = $this->temporaryPdfPath('pdf-layout-');

        try {
            $this->withRepairFallback(
                $inputPath,
                fn (string $sourcePath) => $this->layOutWithFpdi($sourcePath, $laidOutPath, $layout, $crops, $credits),
                'page layout',
            );

            $this->runGhostscript($laidOutPath, $outputPath, $this->compressionArguments($inputPath, $laidOutPath));
        } finally {
            @unlink($laidOutPath);
        }
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

    public function compressWithWatermark(string $inputPath, string $outputPath, string $watermarkText, PageLayout $layout = new PageLayout, ?string $traceId = null): void
    {
        $this->compressWithOverlay($inputPath, $outputPath, $watermarkText, null, $layout, $traceId);
    }

    /**
     * Add the credit header above every page, scatter faint watermark stamps
     * across the page content, then compress the result.
     */
    public function compressWithCreditAndWatermark(string $inputPath, string $outputPath, string $creditText, string $watermarkText, PageLayout $layout = new PageLayout, ?string $traceId = null): void
    {
        $this->compressWithOverlay($inputPath, $outputPath, $creditText, $watermarkText, $layout, $traceId);
    }

    /**
     * Watermark a document in a way that resists stripping.
     *
     * The page content and the marks (header, edge credits, stamps) are built as
     * separate documents so the marks' text can be outlined on its own, without
     * touching the document's real text. They are then laid on top of each
     * other, scanned pages are flattened into images with the marks in their
     * pixels, trace text is added, and the result is compressed and locked.
     */
    private function compressWithOverlay(string $inputPath, string $outputPath, string $headerText, ?string $stampText, PageLayout $layout, ?string $traceId): void
    {
        $this->ensureGhostscriptAvailable();
        $this->ensureFileExists($inputPath);
        $this->ensureValidTraceId($traceId);

        $credits = $layout->removeScannerCredits ? $this->scannerCreditDetector->creditRegionsFor($inputPath) : [];
        $crops = $layout->trimWhitespace ? $this->whitespaceDetector->cropsFor($inputPath, $credits) : [];
        $workspace = $this->temporaryDirectory('pdf-overlay-');

        try {
            $pages = [];

            $this->withRepairFallback(
                $inputPath,
                function (string $sourcePath) use ($workspace, $layout, $crops, $credits, &$pages): void {
                    $pages = $this->layOutUnderHeaderWithFpdi($sourcePath, $workspace.'/content.pdf', $layout, $crops, $credits);
                },
                'watermarking',
            );

            $this->drawMarksWithFpdf($workspace.'/marks.pdf', $pages, $headerText, $stampText);
            $marksPath = $this->outlineText($workspace.'/marks.pdf', $workspace.'/marks-outlined.pdf');
            $this->overlayWithFpdi($workspace.'/content.pdf', $marksPath, $workspace.'/composed.pdf');

            $bakedImages = $this->bakeScannedPages($inputPath, $workspace.'/composed.pdf', $workspace);
            $this->assembleWithFpdi($workspace.'/composed.pdf', $bakedImages, $workspace.'/assembled.pdf', $traceId);

            $this->runGhostscript(
                $workspace.'/assembled.pdf',
                $outputPath,
                $this->compressionArguments($inputPath, $workspace.'/assembled.pdf'),
                $this->lockingArguments(),
                $this->documentInfoPostScript($traceId),
            );
        } finally {
            array_map('unlink', glob($workspace.'/*') ?: []);
            @rmdir($workspace);
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
     * @param  array<int, string>  $finishingArguments  options kept on the crash-safe retry too, such as encryption
     * @param  string|null  $postScript  PostScript run before the input, such as document info pdfmarks
     */
    private function runGhostscript(string $inputPath, string $outputPath, array $arguments, array $finishingArguments = [], ?string $postScript = null): void
    {
        try {
            $this->executeGhostscript($inputPath, $outputPath, [...$arguments, ...$finishingArguments], $postScript);

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
            $this->executeGhostscript($inputPath, $outputPath, [...$this->crashSafeArguments(), ...$finishingArguments], $postScript);
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
    private function executeGhostscript(string $inputPath, string $outputPath, array $arguments, ?string $postScript = null): void
    {
        try {
            $result = Process::path(base_path())
                ->timeout((int) config('pdf.ghostscript.timeout'))
                ->run([
                    (string) config('pdf.ghostscript.binary'),
                    ...$arguments,
                    '-sOutputFile='.$outputPath,
                    ...($postScript === null ? [] : ['-c', $postScript, '-f']),
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
    private function compressionArguments(string $inputPath, ?string $geometryPath = null): array
    {
        $resolution = $this->imageResolutionFor($geometryPath ?? $inputPath);
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
            ...$this->colourConversionArguments($inputPath),
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
     * Opt out of colour conversion for documents Ghostscript would mangle.
     *
     * The /ebook and /screen presets convert everything to sRGB, and that
     * conversion silently drops an image that stores 16 bits per component,
     * carries a soft mask and lives in a calibrated colour space (ICCBased,
     * CalRGB, Lab) — all three together, which is exactly what iOS scans
     * produce. Ghostscript reports success and writes the page out blank, so
     * nothing downstream notices. Device colour spaces skip colour management
     * and are unaffected, so only the bit depth is worth testing for: it is the
     * cheap half of the condition and 16-bit images are rare enough that the
     * output size given up here costs almost nothing.
     *
     * @return array<int, string>
     */
    private function colourConversionArguments(string $inputPath): array
    {
        if (! $this->imageAudit->hasSixteenBitImages($inputPath)) {
            return [];
        }

        Log::info('Document contains 16-bit images; skipping Ghostscript colour conversion.');

        return ['-dColorConversionStrategy=/LeaveColorUnchanged'];
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
    private function imageResolutionFor(string $inputPath, ?int $targetPixelWidth = null): int
    {
        $widthInPoints = $this->geometry->narrowestPageWidthInPoints($inputPath);

        if ($widthInPoints === null) {
            return (int) config('pdf.compression.fallback_image_resolution');
        }

        $widthInInches = $widthInPoints / 72;
        $resolution = (int) ceil(($targetPixelWidth ?? (int) config('pdf.compression.target_pixel_width')) / $widthInInches);

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

    /**
     * Whether every page already has the layout's width, so no FPDI pass is
     * needed. Unreadable geometry is not trusted to fit.
     */
    private function alreadyFitsLayout(string $inputPath, PageLayout $layout): bool
    {
        if ($layout->pageWidthMm === null) {
            return true;
        }

        $widths = $this->geometry->pageWidthsInPoints($inputPath);
        $targetWidth = $layout->pageWidthMm / 25.4 * 72;

        return $widths !== [] && collect($widths)->every(fn (float $width): bool => abs($width - $targetWidth) < 0.5);
    }

    /**
     * @param  array<int, PageRegion>  $crops  keyed by 1-indexed page number
     * @param  array<int, array<int, PageRegion>>  $hiddenRegions  keyed by 1-indexed page number
     */
    private function layOutWithFpdi(string $inputPath, string $outputPath, PageLayout $layout, array $crops, array $hiddenRegions = []): void
    {
        $pdf = $this->newDocument();
        $pageCount = $pdf->setSourceFile($inputPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            [$pageWidth, $pageHeight, $scale] = $this->laidOutSize($pdf, $templateId, $layout, $crops[$page] ?? null);

            $pdf->AddPage($pageWidth > $pageHeight ? 'L' : 'P', [$pageWidth, $pageHeight]);
            $this->placePage($pdf, $templateId, 0, $scale, $crops[$page] ?? null, $hiddenRegions[$page] ?? []);
        }

        $pdf->Output('F', $outputPath);
    }

    /**
     * Lay out the page content with blank room above it for the header.
     *
     * @param  array<int, PageRegion>  $crops  keyed by 1-indexed page number
     * @param  array<int, array<int, PageRegion>>  $hiddenRegions  keyed by 1-indexed page number
     * @return array<int, array{width: float, contentHeight: float, headerHeight: float}> keyed by 1-indexed page number
     */
    private function layOutUnderHeaderWithFpdi(string $inputPath, string $outputPath, PageLayout $layout, array $crops, array $hiddenRegions): array
    {
        $pdf = $this->newDocument();
        $pageCount = $pdf->setSourceFile($inputPath);
        $pages = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            [$pageWidth, $pageHeight, $scale] = $this->laidOutSize($pdf, $templateId, $layout, $crops[$page] ?? null);

            $header = new WatermarkHeader($pageWidth);
            $totalHeight = $pageHeight + $header->heightMm;

            $pdf->AddPage($pageWidth > $totalHeight ? 'L' : 'P', [$pageWidth, $totalHeight]);
            $this->placePage($pdf, $templateId, $header->heightMm, $scale, $crops[$page] ?? null, $hiddenRegions[$page] ?? []);

            $pages[$page] = ['width' => $pageWidth, 'contentHeight' => $pageHeight, 'headerHeight' => $header->heightMm];
        }

        $pdf->Output('F', $outputPath);

        return $pages;
    }

    /**
     * Draw only the marks, on pages matching the laid-out content.
     *
     * @param  array<int, array{width: float, contentHeight: float, headerHeight: float}>  $pages
     */
    private function drawMarksWithFpdf(string $outputPath, array $pages, string $headerText, ?string $stampText): void
    {
        $pdf = $this->newDocument();

        foreach ($pages as $page) {
            $totalHeight = $page['contentHeight'] + $page['headerHeight'];

            $pdf->AddPage($page['width'] > $totalHeight ? 'L' : 'P', [$page['width'], $totalHeight]);
            $this->drawWatermarkHeader($pdf, new WatermarkHeader($page['width']), $headerText);

            if ((bool) config('pdf.watermark.edge.enabled')) {
                $this->drawEdgeCredits($pdf, $page['width'], $page['contentHeight'], $page['headerHeight'], $headerText);
            }

            if ($stampText !== null) {
                $this->drawWatermarkStamps($pdf, new WatermarkStamp($page['width'], $page['contentHeight']), $page['headerHeight'], $stampText);
            }
        }

        $pdf->Output('F', $outputPath);
    }

    /**
     * Turn the marks' text into vector outlines. Returns the path to use: the
     * outlined copy, or the original marks when outlining is off or fails.
     */
    private function outlineText(string $marksPath, string $outlinedPath): string
    {
        if (! (bool) config('pdf.hardening.outline_marks')) {
            return $marksPath;
        }

        try {
            $this->executeGhostscript($marksPath, $outlinedPath, [
                ...$this->baseArguments(),
                '-dCompatibilityLevel=1.4',
                '-dNoOutputFonts',
            ]);

            return $outlinedPath;
        } catch (Throwable $exception) {
            Log::warning('Outlining watermark text failed; keeping it as text.', [
                'exception' => $exception->getMessage(),
            ]);

            return $marksPath;
        }
    }

    private function overlayWithFpdi(string $contentPath, string $marksPath, string $outputPath): void
    {
        $pdf = $this->newDocument();
        $pageCount = $pdf->setSourceFile($contentPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $pdf->setSourceFile($contentPath);
            $contentId = $pdf->importPage($page);
            $pdf->setSourceFile($marksPath);
            $marksId = $pdf->importPage($page);

            $size = $pdf->getTemplateSize($contentId);
            $width = (float) $size['width'];
            $height = (float) $size['height'];

            $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
            $pdf->useTemplate($contentId, 0, 0, $width, $height);
            $pdf->useTemplate($marksId, 0, 0, $width, $height);
        }

        $pdf->Output('F', $outputPath);
    }

    /**
     * Render the scanned pages of the composed document to JPEG, marks and all.
     * Pages are judged on the original document, before any marks were added.
     *
     * @return array<int, string> JPEG paths keyed by 1-indexed page number
     */
    private function bakeScannedPages(string $originalPath, string $composedPath, string $workspace): array
    {
        if (! (bool) config('pdf.hardening.bake_scanned_pages')) {
            return [];
        }

        return $this->rasterizer->renderToJpeg(
            $composedPath,
            $this->textAudit->scannedPages($originalPath),
            $this->imageResolutionFor($composedPath, (int) config('pdf.hardening.baked_pixel_width')),
            $workspace,
        );
    }

    /**
     * Swap baked pages in for their vector originals and add the trace text.
     *
     * @param  array<int, string>  $bakedImages  JPEG paths keyed by 1-indexed page number
     */
    private function assembleWithFpdi(string $composedPath, array $bakedImages, string $outputPath, ?string $traceId): void
    {
        $pdf = $this->newDocument();
        $pageCount = $pdf->setSourceFile($composedPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);
            $width = (float) $size['width'];
            $height = (float) $size['height'];

            $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);

            if (isset($bakedImages[$page])) {
                $pdf->Image($bakedImages[$page], 0, 0, $width, $height, 'JPG');
            } else {
                $pdf->useTemplate($templateId, 0, 0, $width, $height);
            }

            if ($traceId !== null) {
                $pdf->SetFont('Arial', '', 4);
                $pdf->invisibleText(2, $height - 2, PdfTraceReader::PREFIX.$traceId);
            }
        }

        $pdf->Output('F', $outputPath);
    }

    /**
     * Print the credit small along the right and bottom edges of the content.
     */
    private function drawEdgeCredits(StampablePdf $pdf, float $pageWidth, float $contentHeight, float $offsetY, string $text): void
    {
        $text = $this->toCoreFontEncoding($text);
        $inset = (float) config('pdf.watermark.edge.inset_mm');
        [$red, $green, $blue] = $this->rgbFromHex((string) config('pdf.watermark.edge.colour'));
        $blendMode = (string) config('pdf.watermark.edge.blend_mode');
        $opacity = (float) config('pdf.watermark.edge.opacity');

        $edges = [
            // Along the right edge, reading upwards.
            ['length' => $contentHeight, 'angle' => 90.0, 'centre' => fn (float $glyphHeight): array => [$pageWidth - $inset - $glyphHeight / 2, $offsetY + $contentHeight / 2]],
            // Along the bottom edge.
            ['length' => $pageWidth, 'angle' => 0.0, 'centre' => fn (float $glyphHeight): array => [$pageWidth / 2, $offsetY + $contentHeight - $inset - $glyphHeight / 2]],
        ];

        foreach ($edges as $edge) {
            $fontSize = max(
                (float) config('pdf.watermark.edge.min_font_size'),
                $pageWidth * (float) config('pdf.watermark.edge.font_size_per_mm'),
            );

            $pdf->SetFont('Arial', '', $fontSize);
            $available = $edge['length'] * 0.9;

            if ($pdf->GetStringWidth($text) > $available) {
                $fontSize = max((float) config('pdf.watermark.edge.min_font_size'), $fontSize * $available / $pdf->GetStringWidth($text));
                $pdf->SetFont('Arial', '', $fontSize);
            }

            [$centreX, $centreY] = ($edge['centre'])($fontSize * 25.4 / 72);

            $pdf->SetTextColor($red, $green, $blue);
            $pdf->rotatedText($text, $centreX, $centreY, $edge['angle'], $opacity, $blendMode);
        }
    }

    /**
     * Size of the imported page once its crop, if any, is applied and it is
     * scaled to the layout's width, plus the scale factor that took.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function laidOutSize(StampablePdf $pdf, string $templateId, PageLayout $layout, ?PageRegion $crop): array
    {
        $size = $pdf->getTemplateSize($templateId);
        $width = (float) $size['width'];
        $height = (float) $size['height'];

        if ($crop !== null) {
            [$width, $height] = [$crop->widthOf($width), $crop->heightOf($height)];
        }

        [$scaledWidth, $scaledHeight] = $layout->scaledSize($width, $height);

        return [$scaledWidth, $scaledHeight, $width > 0.0 ? $scaledWidth / $width : 1.0];
    }

    /**
     * @param  array<int, PageRegion>  $hiddenRegions
     */
    private function placePage(StampablePdf $pdf, string $templateId, float $y, float $scale, ?PageRegion $crop, array $hiddenRegions = []): void
    {
        $size = $pdf->getTemplateSize($templateId);
        $width = (float) $size['width'] * $scale;
        $height = (float) $size['height'] * $scale;

        if ($crop === null && $hiddenRegions === []) {
            $pdf->useTemplate($templateId, 0, $y, $width, $height);

            return;
        }

        $pdf->useCroppedTemplate($templateId, 0, $y, $width, $height, $crop ?? PageRegion::wholePage(), $hiddenRegions);
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
     * Scatter the watermark over the imported page, offset below the header.
     */
    private function drawWatermarkStamps(StampablePdf $pdf, WatermarkStamp $stamp, float $offsetY, string $text): void
    {
        $placements = $stamp->placements(
            $this->toCoreFontEncoding($text),
            function (string $candidate, float $fontSize) use ($pdf): float {
                $pdf->SetFont('Arial', 'B', $fontSize);

                return $pdf->GetStringWidth($candidate);
            },
            $this->randomizer,
        );

        [$red, $green, $blue] = $this->rgbFromHex((string) config('pdf.watermark.stamp.colour'));
        $blendMode = (string) config('pdf.watermark.stamp.blend_mode');
        $opacity = (float) config('pdf.watermark.stamp.opacity');

        foreach ($placements as $placement) {
            $pdf->SetFont('Arial', 'B', $placement['fontSize']);
            $pdf->SetTextColor($red, $green, $blue);
            $pdf->rotatedText(
                $placement['text'],
                $placement['x'],
                $offsetY + $placement['y'],
                $placement['angleDegrees'],
                $opacity,
                $blendMode,
            );
        }
    }

    /**
     * Parse a `#RRGGBB` colour, falling back to mid grey for anything else.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgbFromHex(string $colour): array
    {
        if (preg_match('/\A#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})\z/i', trim($colour), $parts) !== 1) {
            return [120, 120, 120];
        }

        return [(int) hexdec($parts[1]), (int) hexdec($parts[2]), (int) hexdec($parts[3])];
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

    private function newDocument(): StampablePdf
    {
        $pdf = new StampablePdf;
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        return $pdf;
    }

    /**
     * Encrypt with a random, discarded owner password: viewing and printing
     * stay open, while editing, copying and extracting pages are refused.
     *
     * @return array<int, string>
     */
    private function lockingArguments(): array
    {
        if (! (bool) config('pdf.hardening.lock_editing')) {
            return [];
        }

        /*
         * Permission bits are inverted: everything above bit 12 and the reserved
         * bits 7 and 8 must be set (-3904), then print (4) and high quality
         * print (2048) are granted on top.
         */
        return [
            '-dEncryptionR=3',
            '-dKeyLength=128',
            '-sOwnerPassword='.bin2hex(random_bytes(16)),
            '-dPermissions='.(-3904 + 4 + 2048),
        ];
    }

    /**
     * A pdfmark writing the trace ID into the document info. The values are
     * hex strings, so nothing in the ID can break out of the PostScript.
     */
    private function documentInfoPostScript(?string $traceId): ?string
    {
        if ($traceId === null) {
            return null;
        }

        return sprintf(
            '[ /DIUQBankTraceId <%s> /Keywords <%s> /DOCINFO pdfmark',
            bin2hex($traceId),
            bin2hex(PdfTraceReader::PREFIX.$traceId),
        );
    }

    private function ensureValidTraceId(?string $traceId): void
    {
        if ($traceId !== null && preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/', $traceId) !== 1) {
            throw new RuntimeException('The trace ID may only contain letters, numbers, dots, dashes, colons and underscores.');
        }
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a temporary directory.');
        }

        return $directory;
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
