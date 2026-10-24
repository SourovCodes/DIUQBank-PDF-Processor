<?php

namespace App\Support\PdfCompression;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Finds the white margins around each page's content.
 *
 * Every page is rendered to a small greyscale bitmap and scanned inwards from
 * each edge for the first row or column holding enough non-white pixels. Trimming
 * is a nicety, so any failure is logged and the document is left uncropped.
 */
class PdfWhitespaceDetector
{
    /**
     * Crops keyed by 1-indexed page number. Pages without a worthwhile crop
     * (blank, already tight, or unreadable) are absent.
     *
     * @param  array<int, array<int, PageRegion>>  $ignoredRegions  areas that will be hidden anyway, keyed by page number
     * @return array<int, PageRegion>
     */
    public function cropsFor(string $inputPath, array $ignoredRegions = []): array
    {
        $directory = $this->temporaryDirectory();

        try {
            $result = Process::path(base_path())
                ->timeout((int) config('pdf.ghostscript.timeout'))
                ->run([
                    (string) config('pdf.ghostscript.binary'),
                    '-sDEVICE=pgmraw',
                    '-dNOPAUSE',
                    '-dBATCH',
                    '-dQUIET',
                    '-dSAFER',
                    // FPDI imports the crop box, so measure the same area.
                    '-dUseCropBox',
                    sprintf('-r%d', (int) config('pdf.trim.render_resolution')),
                    '-sOutputFile='.$directory.'/page-%d.pgm',
                    $inputPath,
                ]);

            if ($result->failed()) {
                Log::warning('Whitespace detection failed; leaving pages uncropped.', [
                    'error' => trim($result->errorOutput()),
                ]);

                return [];
            }

            $crops = [];

            for ($page = 1; is_file($bitmap = $directory.'/page-'.$page.'.pgm'); $page++) {
                $crop = $this->cropForBitmap((string) file_get_contents($bitmap), $ignoredRegions[$page] ?? []);

                if ($crop !== null) {
                    $crops[$page] = $crop;
                }
            }

            return $crops;
        } catch (Throwable $exception) {
            Log::warning('Whitespace detection failed; leaving pages uncropped.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
    }

    /**
     * Measure the content of one binary PGM (P5) bitmap.
     *
     * @param  array<int, PageRegion>  $ignoredRegions  areas treated as white, such as a scanner credit
     */
    public function cropForBitmap(string $bitmap, array $ignoredRegions = []): ?PageRegion
    {
        if (preg_match('/\AP5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+(\d+)\s/', $bitmap, $header) !== 1 || (int) $header[3] > 255) {
            return null;
        }

        $width = (int) $header[1];
        $height = (int) $header[2];
        $pixels = substr($bitmap, strlen($header[0]));

        if ($width === 0 || $height === 0 || strlen($pixels) < $width * $height) {
            return null;
        }

        foreach ($ignoredRegions as $region) {
            $pixels = $this->whitenRegion($pixels, $width, $height, $region);
        }

        $isContentRow = fn (int $y): bool => $this->countDarkPixels(substr($pixels, $y * $width, $width)) >= $this->minimumContentPixels();
        $isContentColumn = fn (int $x): bool => $this->columnHasContent($pixels, $width, $height, $x);

        $top = $this->firstMatching(0, $height - 1, $isContentRow);

        if ($top === null) {
            return null;
        }

        $bottom = (int) $this->firstMatching($height - 1, $top, $isContentRow);
        $left = (int) $this->firstMatching(0, $width - 1, $isContentColumn);
        $right = (int) $this->firstMatching($width - 1, $left, $isContentColumn);

        $padding = (float) config('pdf.trim.padding_points') / 72 * (int) config('pdf.trim.render_resolution');

        $crop = new PageRegion(
            left: max(0.0, ($left - $padding) / $width),
            top: max(0.0, ($top - $padding) / $height),
            right: min(1.0, ($right + 1 + $padding) / $width),
            bottom: min(1.0, ($bottom + 1 + $padding) / $height),
        );

        $keepThreshold = 1.0 - (float) config('pdf.trim.min_trim_ratio');

        if ($crop->widthOf(1.0) >= $keepThreshold && $crop->heightOf(1.0) >= $keepThreshold) {
            return null;
        }

        return $crop;
    }

    private function whitenRegion(string $pixels, int $width, int $height, PageRegion $region): string
    {
        $left = max(0, (int) floor($region->left * $width));
        $right = min($width, (int) ceil($region->right * $width));
        $top = max(0, (int) floor($region->top * $height));
        $bottom = min($height, (int) ceil($region->bottom * $height));

        if ($left >= $right) {
            return $pixels;
        }

        $white = str_repeat("\xFF", $right - $left);

        for ($y = $top; $y < $bottom; $y++) {
            $pixels = substr_replace($pixels, $white, $y * $width + $left, $right - $left);
        }

        return $pixels;
    }

    /**
     * Walk from one index towards another and return the first that matches.
     *
     * @param  callable(int): bool  $matches
     */
    private function firstMatching(int $from, int $to, callable $matches): ?int
    {
        $step = $from <= $to ? 1 : -1;

        for ($index = $from; $index !== $to + $step; $index += $step) {
            if ($matches($index)) {
                return $index;
            }
        }

        return null;
    }

    private function columnHasContent(string $pixels, int $width, int $height, int $x): bool
    {
        $threshold = (int) config('pdf.trim.white_threshold');
        $needed = $this->minimumContentPixels();

        for ($y = 0; $y < $height; $y++) {
            if (ord($pixels[$y * $width + $x]) < $threshold && --$needed === 0) {
                return true;
            }
        }

        return false;
    }

    private function countDarkPixels(string $row): int
    {
        $threshold = (int) config('pdf.trim.white_threshold');

        if ($threshold <= 0) {
            return 0;
        }

        return (int) preg_match_all(sprintf('/[\x00-\x%02X]/', $threshold - 1), $row);
    }

    /**
     * A lone speck of scanner dust should not hold a whole margin open.
     */
    private function minimumContentPixels(): int
    {
        return max(1, (int) config('pdf.trim.min_content_pixels'));
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/pdf-trim-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        return $directory;
    }
}
