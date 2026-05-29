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

test('extract pages endpoint requires api key', function (): void {
    $response = $this->post('/api/pdfs/extract-pages');

    $response
        ->assertUnauthorized()
        ->assertJson([
            'message' => 'Unauthorized.',
        ]);
});

test('extract pages endpoint validates pages parameter', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pages']);
});

test('extract pages endpoint rejects malformed page strings', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'pages' => '1,abc,3',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pages']);
});

test('extract pages endpoint rejects pdfs larger than 30 mb', function (): void {
    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('oversized.pdf', 30721, 'application/pdf'),
            'pages' => '1',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pdf']);
});

test('extract pages endpoint expands range strings', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('extractPages')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, array $pages): void {
                expect(file_exists($inputPath))->toBeTrue();
                expect($pages)->toBe([1, 3, 5, 6, 7]);

                file_put_contents($outputPath, '%PDF-1.4 extracted');
            });
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'pages' => '1,3,5-7',
        ]);

    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename="source-extracted.pdf"')
        ->assertContent('%PDF-1.4 extracted');
});

test('extract pages endpoint preserves array order', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('extractPages')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, array $pages): void {
                expect($pages)->toBe([3, 1, 5]);

                file_put_contents($outputPath, '%PDF-1.4 reordered');
            });
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'pages' => [3, 1, 5],
        ]);

    $response->assertOk();
});

test('extract pages endpoint dedupes pages while keeping first occurrence', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('extractPages')
            ->once()
            ->andReturnUsing(function (string $inputPath, string $outputPath, array $pages): void {
                expect($pages)->toBe([1, 2]);

                file_put_contents($outputPath, '%PDF-1.4 deduped');
            });
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'pages' => '1,1,2',
        ]);

    $response->assertOk();
});

test('extract pages endpoint returns 422 when service reports out of range', function (): void {
    $this->mock(PdfCompressor::class, function (MockInterface $mock): void {
        $mock->shouldReceive('extractPages')
            ->once()
            ->andThrow(new RuntimeException('Requested page 99 is out of range (document has 8 pages).'));
    });

    $response = $this
        ->withHeader('X-API-Key', 'test-api-key')
        ->post('/api/pdfs/extract-pages', [
            'pdf' => UploadedFile::fake()->create('source.pdf', 32, 'application/pdf'),
            'pages' => '99',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Requested page 99 is out of range (document has 8 pages).',
        ]);
});

test('docs page is public', function (): void {
    $response = $this->get('/docs');

    $response
        ->assertOk()
        ->assertSee('/openapi.yaml', escape: false);
});
