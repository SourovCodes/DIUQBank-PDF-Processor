<?php

return [
    'api_key' => env('PDF_API_KEY', ''),

    'ghostscript' => [
        'binary' => env('GHOSTSCRIPT_BINARY', 'gs'),
        'timeout' => (int) env('GHOSTSCRIPT_TIMEOUT', 120),
        'preset' => env('GHOSTSCRIPT_PRESET', 'ebook'),
    ],

    'compression' => [
        /*
        |----------------------------------------------------------------------
        | Image resolution targets
        |----------------------------------------------------------------------
        |
        | Ghostscript downsamples images relative to the page size in points, so
        | a fixed DPI silently destroys scans that live on undersized pages: a
        | 210x297 *point* page (a quarter of A4, produced by scanners that treat
        | A4's millimetre numbers as points) keeps only 437 pixels across at the
        | /ebook preset's 150 DPI. We therefore target a pixel width and derive
        | the DPI from the document's own narrowest page, clamping the result so
        | neither tiny nor poster-sized pages produce absurd resolutions.
        |
        */

        'target_pixel_width' => (int) env('PDF_TARGET_PIXEL_WIDTH', 1700),
        'min_image_resolution' => (int) env('PDF_MIN_IMAGE_RESOLUTION', 72),
        'max_image_resolution' => (int) env('PDF_MAX_IMAGE_RESOLUTION', 600),
        'mono_pixel_width_multiplier' => 2,
        'min_mono_image_resolution' => 300,
        'max_mono_image_resolution' => 1200,

        /*
        | Fallback DPI used when a document's page geometry cannot be parsed.
        | Chosen so that a standard A4/Letter page keeps roughly the same pixel
        | budget as `target_pixel_width`.
        */
        'fallback_image_resolution' => (int) env('PDF_FALLBACK_IMAGE_RESOLUTION', 200),
    ],

    'watermark' => [
        /*
        |----------------------------------------------------------------------
        | Header geometry
        |----------------------------------------------------------------------
        |
        | The header is sized relative to the page width so it stays visually
        | proportional on any page size. The ratios below reproduce the original
        | 8mm tall / 8pt / 2mm padded header on a 210mm wide A4 page.
        |
        */

        'header_height_ratio' => 8 / 210,
        'min_header_height_mm' => 4.0,
        'max_header_height_mm' => 12.0,
        'font_size_per_mm' => 1.0,
        'min_font_size' => 5.0,
        'side_padding_ratio' => 2 / 210,
        'min_side_padding_mm' => 1.0,
    ],
];
