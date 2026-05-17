<?php

use App\Support\PdfCompression\PdfCompressor;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;

beforeEach(function (): void {
    config()->set('pdf.api_key', 'test-api-key');
});

test('compress endpoint requires api key', function (): void {
    $response = $this->post('/api/pdfs/compress');

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized.',
        ]);
});

test('compress endpoint validates pdf upload', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/compress');

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});

test('compress endpoint rejects pdfs larger than 30 mb', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/compress', [
            'pdf' => UploadedFile::fake()->create('oversized.pdf', 30721, 'application/pdf'),
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});

test('compress endpoint returns a pdf response', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('compress')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath): void {
                expect(file_exists($inputPath))->toBeTrue();

                file_put_contents($outputPath, '%PDF-1.4 compressed');
            });
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
        ]);

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename="source-compressed.pdf"')
        ->assertContent('%PDF-1.4 compressed');
});

test('watermark and compress endpoint validates watermark text', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['watermark_text']);
});

test('watermark and compress endpoint rejects pdfs larger than 30 mb', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/watermark-compress', [
            'pdf' => UploadedFile::fake()->create('oversized.pdf', 30721, 'application/pdf'),
            'watermark_text' => 'DIU Question Bank',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});

test('watermark and compress endpoint returns a pdf response', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('compressWithWatermark')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, string $watermarkText): void {
                expect(file_exists($inputPath))->toBeTrue();
                expect($watermarkText)->toBe('DIU Question Bank');

                file_put_contents($outputPath, '%PDF-1.4 watermarked');
            });
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/watermark-compress', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'watermark_text' => 'DIU Question Bank',
        ]);

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename="source-watermarked-compressed.pdf"')
        ->assertContent('%PDF-1.4 watermarked');
});

test('docs page is public', function (): void {
    $response = $this->get('/docs');

    $response
        ->assertOk()
        ->assertSee('/openapi.yaml', escape: false);
});
