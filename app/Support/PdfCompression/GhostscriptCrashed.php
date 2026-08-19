<?php

namespace App\Support\PdfCompression;

use RuntimeException;
use Throwable;

/**
 * Raised when the Ghostscript process is killed by a signal rather than exiting.
 */
class GhostscriptCrashed extends RuntimeException
{
    public function __construct(public readonly int $signal, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Ghostscript was terminated by signal %d.', $signal), 0, $previous);
    }
}
