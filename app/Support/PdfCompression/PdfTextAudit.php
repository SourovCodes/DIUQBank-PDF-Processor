<?php

namespace App\Support\PdfCompression;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Tells scanned pages from pages with real text, using Ghostscript's text
 * extraction. A failure reports no scanned pages, so nothing gets flattened.
 */
class PdfTextAudit
{
    /**
     * 1-indexed numbers of the pages holding at most the configured number of
     * text characters (whitespace ignored).
     *
     * @return array<int, int>
     */
    public function scannedPages(string $inputPath): array
    {
        $directory = sys_get_temp_dir().'/pdf-text-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        try {
            $result = Process::path(base_path())
                ->timeout((int) config('pdf.ghostscript.timeout'))
                ->run([
                    (string) config('pdf.ghostscript.binary'),
                    '-sDEVICE=txtwrite',
                    '-dNOPAUSE',
                    '-dBATCH',
                    '-dQUIET',
                    '-dSAFER',
                    '-sOutputFile='.$directory.'/page-%d.txt',
                    $inputPath,
                ]);

            if ($result->failed()) {
                Log::warning('Text audit failed; no pages will be flattened.', [
                    'error' => trim($result->errorOutput()),
                ]);

                return [];
            }

            $limit = (int) config('pdf.hardening.scanned_page_max_characters');
            $scannedPages = [];

            for ($page = 1; is_file($textPath = $directory.'/page-'.$page.'.txt'); $page++) {
                $text = (string) preg_replace('/\s+/u', '', (string) file_get_contents($textPath));

                if (mb_strlen($text) <= $limit) {
                    $scannedPages[] = $page;
                }
            }

            return $scannedPages;
        } catch (Throwable $exception) {
            Log::warning('Text audit failed; no pages will be flattened.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
    }
}
