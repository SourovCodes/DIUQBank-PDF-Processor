<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompressPdfRequest;
use App\Http\Requests\CreditWatermarkCompressPdfRequest;
use App\Http\Requests\ExtractPdfPagesRequest;
use App\Http\Requests\InspectPdfRequest;
use App\Http\Requests\WatermarkCompressPdfRequest;
use App\Support\PdfCompression\PageLayout;
use App\Support\PdfCompression\PdfCompressor;
use App\Support\PdfCompression\PdfTraceReader;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PdfController extends Controller
{
    public function compress(CompressPdfRequest $request, PdfCompressor $compressor): Response
    {
        /** @var UploadedFile $uploadedPdf */
        $uploadedPdf = $request->file('pdf');
        $layout = $this->pageLayout($request);

        return $this->processPdf(
            uploadedPdf: $uploadedPdf,
            outputFilename: $this->outputFilename($uploadedPdf, 'compressed'),
            processor: static function (string $inputPath, string $outputPath) use ($compressor, $layout): void {
                $compressor->compress($inputPath, $outputPath, $layout);
            },
        );
    }

    public function watermarkAndCompress(WatermarkCompressPdfRequest $request, PdfCompressor $compressor): Response
    {
        /** @var UploadedFile $uploadedPdf */
        $uploadedPdf = $request->file('pdf');
        $watermarkText = trim((string) $request->validated()['watermark_text']);
        $layout = $this->pageLayout($request);
        $traceId = $this->traceId($request);

        return $this->processPdf(
            uploadedPdf: $uploadedPdf,
            outputFilename: $this->outputFilename($uploadedPdf, 'watermarked-compressed'),
            processor: static function (string $inputPath, string $outputPath) use ($compressor, $watermarkText, $layout, $traceId): void {
                $compressor->compressWithWatermark($inputPath, $outputPath, $watermarkText, $layout, $traceId);
            },
            headers: ['X-PDF-Trace-Id' => $traceId],
        );
    }

    public function creditWatermarkAndCompress(CreditWatermarkCompressPdfRequest $request, PdfCompressor $compressor): Response
    {
        /** @var UploadedFile $uploadedPdf */
        $uploadedPdf = $request->file('pdf');
        $creditText = trim((string) $request->validated()['credit_text']);
        $watermarkText = trim((string) $request->validated()['watermark_text']);
        $layout = $this->pageLayout($request);
        $traceId = $this->traceId($request);

        return $this->processPdf(
            uploadedPdf: $uploadedPdf,
            outputFilename: $this->outputFilename($uploadedPdf, 'watermarked-compressed'),
            processor: static function (string $inputPath, string $outputPath) use ($compressor, $creditText, $watermarkText, $layout, $traceId): void {
                $compressor->compressWithCreditAndWatermark($inputPath, $outputPath, $creditText, $watermarkText, $layout, $traceId);
            },
            headers: ['X-PDF-Trace-Id' => $traceId],
        );
    }

    public function extractPages(ExtractPdfPagesRequest $request, PdfCompressor $compressor): Response
    {
        /** @var UploadedFile $uploadedPdf */
        $uploadedPdf = $request->file('pdf');

        /** @var array<int, int> $rawPages */
        $rawPages = $request->validated()['pages'];
        $pages = array_values(array_unique(array_map('intval', $rawPages)));

        return $this->processPdf(
            uploadedPdf: $uploadedPdf,
            outputFilename: $this->outputFilename($uploadedPdf, 'extracted'),
            processor: static function (string $inputPath, string $outputPath) use ($compressor, $pages): void {
                $compressor->extractPages($inputPath, $outputPath, $pages);
            },
        );
    }

    private function pageLayout(Request $request): PageLayout
    {
        return PageLayout::fromConfig(
            trimWhitespace: $request->boolean('trim_whitespace', (bool) config('pdf.trim.enabled')),
            removeScannerCredits: $request->boolean('remove_scanner_credit', (bool) config('pdf.scanner_credit.remove')),
        );
    }

    /**
     * Report the trace IDs hidden in a PDF this service watermarked, so a copy
     * found elsewhere can be matched to its upload.
     */
    public function inspect(InspectPdfRequest $request, PdfTraceReader $traceReader): JsonResponse
    {
        /** @var UploadedFile $uploadedPdf */
        $uploadedPdf = $request->file('pdf');
        $inputPath = $uploadedPdf->getRealPath();

        if (! is_string($inputPath) || $inputPath === '' || ! file_exists($inputPath)) {
            return new JsonResponse([
                'message' => 'The uploaded PDF could not be processed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $traceIds = $traceReader->traceIdsFor($inputPath);
        } catch (RuntimeException $exception) {
            return $this->processingErrorResponse($exception);
        }

        return new JsonResponse([
            'traced' => $traceIds !== [],
            'trace_ids' => $traceIds,
        ]);
    }

    /**
     * The caller's own upload reference when given, otherwise a fresh ID the
     * caller can store from the response header.
     */
    private function traceId(Request $request): string
    {
        $traceId = trim((string) $request->input('trace_id', ''));

        return $traceId !== '' ? $traceId : (string) Str::ulid();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function processPdf(UploadedFile $uploadedPdf, string $outputFilename, Closure $processor, array $headers = []): Response
    {
        $inputPath = $uploadedPdf->getRealPath();

        if (! is_string($inputPath) || $inputPath === '' || ! file_exists($inputPath)) {
            return new JsonResponse([
                'message' => 'The uploaded PDF could not be processed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $temporaryOutputPath = $this->temporaryPdfPath('pdf-api-output-');

        try {
            $processor($inputPath, $temporaryOutputPath);

            $outputContents = file_get_contents($temporaryOutputPath);

            if ($outputContents === false || $outputContents === '') {
                throw new RuntimeException('Unable to read the generated PDF output.');
            }

            return response($outputContents, Response::HTTP_OK, [
                ...$headers,
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $outputFilename),
                'Content-Length' => (string) strlen($outputContents),
            ]);
        } catch (RuntimeException $exception) {
            return $this->processingErrorResponse($exception);
        } catch (Throwable $exception) {
            report($exception);

            return new JsonResponse([
                'message' => 'PDF processing failed.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            @unlink($temporaryOutputPath);
        }
    }

    private function processingErrorResponse(RuntimeException $exception): JsonResponse
    {
        $status = str_contains($exception->getMessage(), 'Ghostscript binary')
            ? Response::HTTP_INTERNAL_SERVER_ERROR
            : Response::HTTP_UNPROCESSABLE_ENTITY;

        return new JsonResponse([
            'message' => $exception->getMessage(),
        ], $status);
    }

    private function outputFilename(UploadedFile $uploadedPdf, string $suffix): string
    {
        $originalName = pathinfo($uploadedPdf->getClientOriginalName(), PATHINFO_FILENAME);
        $sanitizedName = (string) Str::of($originalName)
            ->trim()
            ->replaceMatches('/[^A-Za-z0-9]+/', '-')
            ->trim('-');

        if ($sanitizedName === '') {
            $sanitizedName = 'document';
        }

        return sprintf('%s-%s.pdf', $sanitizedName, $suffix);
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
