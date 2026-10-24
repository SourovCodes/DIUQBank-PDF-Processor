<?php

namespace App\Support\PdfCompression;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Renders chosen pages to JPEG files with Ghostscript, so everything drawn on
 * them (scan, header, stamps) ends up in the same pixels. A failure renders
 * nothing and the pages stay vector.
 */
class PdfPageRasterizer
{
    /**
     * @param  array<int, int>  $pages  1-indexed page numbers
     * @return array<int, string> JPEG paths inside `$directory`, keyed by page number
     */
    public function renderToJpeg(string $inputPath, array $pages, int $resolution, string $directory): array
    {
        if ($pages === []) {
            return [];
        }

        sort($pages);

        try {
            $result = Process::path(base_path())
                ->timeout((int) config('pdf.ghostscript.timeout'))
                ->run([
                    (string) config('pdf.ghostscript.binary'),
                    '-sDEVICE=jpeg',
                    '-dNOPAUSE',
                    '-dBATCH',
                    '-dQUIET',
                    '-dSAFER',
                    '-dTextAlphaBits=4',
                    '-dGraphicsAlphaBits=4',
                    sprintf('-dJPEGQ=%d', (int) config('pdf.hardening.baked_jpeg_quality')),
                    sprintf('-r%d', $resolution),
                    '-sPageList='.implode(',', $pages),
                    '-sOutputFile='.$directory.'/baked-%d.jpg',
                    $inputPath,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Rendering pages failed; leaving them as vector pages.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($result->failed()) {
            Log::warning('Rendering pages failed; leaving them as vector pages.', [
                'error' => trim($result->errorOutput()),
            ]);

            return [];
        }

        // Output files are numbered in rendering order, not by page number.
        $images = [];

        foreach (array_values($pages) as $index => $page) {
            $imagePath = $directory.'/baked-'.($index + 1).'.jpg';

            if (is_file($imagePath) && filesize($imagePath) > 0) {
                $images[$page] = $imagePath;
            }
        }

        return $images;
    }
}
