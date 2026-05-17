# DIUQBank PDF Processor API

This project is a small Laravel backend that supports the DIU Question Bank platform at https://diuqbank.com.

It exposes two PDF-processing endpoints behind a single API key and publishes OpenAPI documentation through Scalar. The website surface is intentionally minimal: the homepage redirects to the docs page, and branded error pages guide users back to the docs or the main DIUQBank website.

## Features

- Compress a PDF with Ghostscript using the `ebook` preset.
- Add a DIUQBank-style header watermark to every page, then compress the PDF.
- Protect API endpoints with an `X-API-Key` header from `.env`.
- Publish public API reference docs at `/docs` using Scalar and `/openapi.yaml`.
- Avoid queues and database requirements for the core API flow.

## API Endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/pdfs/compress` | Accepts a `pdf` upload and returns a compressed PDF. |
| `POST` | `/api/pdfs/watermark-compress` | Accepts a `pdf` upload and `watermark_text`, then returns the watermarked and compressed PDF. |

All API requests must include:

```http
X-API-Key: your-secret-api-key
```

## Requirements

- PHP 8.4+
- Composer
- Node.js and npm if you want to rebuild frontend assets
- Ghostscript installed and available on `PATH`

Verify Ghostscript locally with:

```bash
gs --version
```

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
```

Update your `.env` with at least these values:

```env
PDF_API_KEY=change-this
GHOSTSCRIPT_BINARY=gs
GHOSTSCRIPT_TIMEOUT=120
GHOSTSCRIPT_PRESET=ebook
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

Start the local stack with:

```bash
composer dev
```

## Documentation

- Homepage: `/` redirects to `/docs`
- Scalar UI: `/docs`
- OpenAPI file: `/openapi.yaml`

## Example Requests

Compress a PDF:

```bash
curl -X POST http://127.0.0.1:8000/api/pdfs/compress \
	-H "X-API-Key: change-this" \
	-F "pdf=@/absolute/path/to/file.pdf" \
	--output compressed.pdf
```

Watermark and compress a PDF:

```bash
curl -X POST http://127.0.0.1:8000/api/pdfs/watermark-compress \
	-H "X-API-Key: change-this" \
	-F "pdf=@/absolute/path/to/file.pdf" \
	-F "watermark_text=For more questions: https://diuqbank.com" \
	--output watermarked-compressed.pdf
```

## Testing

Run the focused test suite with:

```bash
php artisan test --compact tests/Feature/PdfApiTest.php tests/Feature/WebPagesTest.php
```

## Notes

- API validation and auth errors return JSON responses.
- Successful processing responses return `application/pdf` output directly.
- The error pages are branded for the DIUQBank PDF Processor site and point users back to the docs and https://diuqbank.com.
