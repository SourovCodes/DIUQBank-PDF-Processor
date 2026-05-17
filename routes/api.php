<?php

use App\Http\Controllers\Api\PdfController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->group(function (): void {
    Route::post('/pdfs/compress', [PdfController::class, 'compress'])->name('api.pdfs.compress');
    Route::post('/pdfs/watermark-compress', [PdfController::class, 'watermarkAndCompress'])->name('api.pdfs.watermark-compress');
});
