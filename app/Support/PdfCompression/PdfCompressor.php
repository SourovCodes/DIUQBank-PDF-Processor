<?php

namespace App\Support\PdfCompression;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

class PdfCompressor
{
    private const HEADER_HEIGHT_MM = 8;

    private const FONT_SIZE = 8;

    private const SIDE_PADDING_MM = 2;

    public function compress(string $inputPath, string $outputPath): void
    {
        $this->ensureGhostscriptAvailable();
        $this->ensureFileExists($inputPath);

        $result = Process::path(base_path())
            ->timeout((int) config('pdf.ghostscript.timeout'))
            ->run([
                (string) config('pdf.ghostscript.binary'),
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                sprintf('-dPDFSETTINGS=/%s', (string) config('pdf.ghostscript.preset')),
                '-dNOPAUSE',
                '-dBATCH',
                '-dQUIET',
                '-sOutputFile='.$outputPath,
                $inputPath,
            ]);

        if ($result->failed()) {
            throw new RuntimeException(
                trim($result->errorOutput()) !== ''
                    ? 'Ghostscript compression failed: '.trim($result->errorOutput())
                    : 'Ghostscript failed to compress the PDF.'
            );
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('Ghostscript did not produce a compressed PDF file.');
        }
    }

    public function compressWithWatermark(string $inputPath, string $outputPath, string $watermarkText): void
    {
        $this->ensureGhostscriptAvailable();
        $this->ensureFileExists($inputPath);

        $watermarkedPath = $this->temporaryPdfPath('pdf-watermark-');

        try {
            $this->createWatermarkedPdf($inputPath, $watermarkedPath, $watermarkText);
            $this->compress($watermarkedPath, $outputPath);
        } finally {
            @unlink($watermarkedPath);
        }
    }

    private function createWatermarkedPdf(string $inputPath, string $outputPath, string $watermarkText): void
    {
        try {
            $this->addWatermarkWithFpdi($inputPath, $outputPath, $watermarkText);

            return;
        } catch (Throwable $exception) {
            $normalizedPath = $this->temporaryPdfPath('pdf-normalized-');

            try {
                $this->compress($inputPath, $normalizedPath);
                $this->addWatermarkWithFpdi($normalizedPath, $outputPath, $watermarkText);

                return;
            } catch (Throwable $normalizedException) {
                throw new RuntimeException(
                    'FPDI watermarking failed after Ghostscript normalization: '.$normalizedException->getMessage(),
                    0,
                    $normalizedException,
                );
            } finally {
                @unlink($normalizedPath);
            }
        }
    }

    private function addWatermarkWithFpdi(string $inputPath, string $outputPath, string $watermarkText): void
    {
        $pdf = new Fpdi;
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        $pageCount = $pdf->setSourceFile($inputPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);

            $pageWidth = (float) $size['width'];
            $pageHeight = (float) $size['height'];
            $totalHeight = $pageHeight + self::HEADER_HEIGHT_MM;
            $orientation = $pageWidth > $totalHeight ? 'L' : 'P';

            $pdf->AddPage($orientation, [$pageWidth, $totalHeight]);
            $this->drawWatermarkHeader($pdf, $pageWidth, $watermarkText);
            $pdf->useTemplate($templateId, 0, self::HEADER_HEIGHT_MM, $pageWidth, $pageHeight);
        }

        $pdf->Output('F', $outputPath);
    }

    private function drawWatermarkHeader(Fpdi $pdf, float $pageWidth, string $text): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, $pageWidth, self::HEADER_HEIGHT_MM, 'F');

        $pdf->SetDrawColor(210, 210, 210);
        $pdf->Line(0, self::HEADER_HEIGHT_MM, $pageWidth, self::HEADER_HEIGHT_MM);

        $pdf->SetFont('Arial', '', self::FONT_SIZE);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY(self::SIDE_PADDING_MM, self::HEADER_HEIGHT_MM * 0.25);
        $pdf->Cell($pageWidth - (self::SIDE_PADDING_MM * 2), self::HEADER_HEIGHT_MM * 0.5, $text, 0, 0, 'L');
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
