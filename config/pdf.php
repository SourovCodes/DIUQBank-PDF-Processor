<?php

return [
    'api_key' => env('PDF_API_KEY', ''),

    'ghostscript' => [
        'binary' => env('GHOSTSCRIPT_BINARY', 'gs'),
        'timeout' => (int) env('GHOSTSCRIPT_TIMEOUT', 120),
        'preset' => env('GHOSTSCRIPT_PRESET', 'ebook'),
    ],
];
