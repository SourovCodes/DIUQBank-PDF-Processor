<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompressPdfRequest;
use App\Http\Requests\WatermarkCompressPdfRequest;
use App\Support\PdfCompression\PdfCompressor;
use Closure;
use Illuminate\Http\JsonResponse;
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

        return $this->processPdf(
            uploadedPdf: $uploadedPdf,
            outputFilename: $this->outputFilename($uploadedPdf, 'compressed'),
            processor: static function (string $inputPath, string $outputPath) use ($compressor): void {
                $compressor->compress($inputPath, $outputPath);
            },
        );
    }

    public function watermarkAndCompress(WatermarkCompressPdfRequest $request, PdfCompressor $compressor): Response
    {
        /** @var UploadedFile $uploadedPdf */
        $uploadedPdf = $request->file('pdf');
        $watermarkText = trim((string) $request->validated()['watermark_text']);

        return $this->processPdf(
            uploadedPdf: $uploadedPdf,
            outputFilename: $this->outputFilename($uploadedPdf, 'watermarked-compressed'),
            processor: static function (string $inputPath, string $outputPath) use ($compressor, $watermarkText): void {
                $compressor->compressWithWatermark($inputPath, $outputPath, $watermarkText);
            },
        );
    }

    private function processPdf(UploadedFile $uploadedPdf, string $outputFilename, Closure $processor): Response
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
