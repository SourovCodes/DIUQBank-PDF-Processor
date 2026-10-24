<?php

namespace App\Support\PdfCompression;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads back the trace IDs that watermarking hides in a document.
 *
 * Each page carries the ID as invisible text, which Ghostscript extracts even
 * from documents locked against editing, so a reposted copy can be matched to
 * the upload it came from.
 */
class PdfTraceReader
{
    public const PREFIX = 'DIUQBank trace ';

    /**
     * Distinct trace IDs found in the document, in page order.
     *
     * @return array<int, string>
     */
    public function traceIdsFor(string $inputPath): array
    {
        $result = Process::path(base_path())
            ->timeout((int) config('pdf.ghostscript.timeout'))
            ->run([
                (string) config('pdf.ghostscript.binary'),
                '-sDEVICE=txtwrite',
                '-dNOPAUSE',
                '-dBATCH',
                '-dQUIET',
                '-dSAFER',
                '-sOutputFile=-',
                $inputPath,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('Ghostscript could not read this PDF.');
        }

        preg_match_all('/'.preg_quote(self::PREFIX, '/').'([A-Za-z0-9._:-]{1,100})/', $result->output(), $matches);

        return array_values(array_unique($matches[1]));
    }
}
