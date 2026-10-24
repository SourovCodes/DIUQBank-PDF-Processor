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

    'layout' => [
        /*
        |----------------------------------------------------------------------
        | Uniform page width
        |----------------------------------------------------------------------
        |
        | Every page is scaled to this width in millimetres, after any trimming,
        | with its height scaled to match so the aspect ratio is kept. A uniform
        | width makes documents read smoothly when scrolling on a phone. Set to 0
        | to keep each page's own width.
        |
        */

        'page_width_mm' => (float) env('PDF_PAGE_WIDTH_MM', 210),
    ],

    'scanner_credit' => [
        /*
        |----------------------------------------------------------------------
        | Scanner app credits
        |----------------------------------------------------------------------
        |
        | CamScanner stamps its logo and name in a corner of every page and lays
        | a link to its site over them. Any link to these domains marks a credit
        | to hide; whitespace trimming then ignores that corner too.
        |
        | `remove` is the default for API requests, which may override it with
        | the `remove_scanner_credit` field.
        |
        */

        'remove' => (bool) env('PDF_REMOVE_SCANNER_CREDIT', true),
        'link_domains' => ['camscanner.com', 'camscanner.net', 'intsig.com'],
    ],

    'trim' => [
        /*
        |----------------------------------------------------------------------
        | Whitespace trimming
        |----------------------------------------------------------------------
        |
        | Phone photos of question papers are often dropped onto a Letter or A4
        | page, leaving wide white margins around the actual content. Each page
        | is rendered at a low resolution and cropped to the darker-than-white
        | pixels plus some padding. Rendering (rather than reading the drawing
        | bounding box) also catches white borders baked into scanned images.
        |
        | `enabled` is the default for API requests, which may override it with
        | the `trim_whitespace` field.
        |
        */

        'enabled' => (bool) env('PDF_TRIM_WHITESPACE', true),
        'render_resolution' => 36,
        'white_threshold' => 235,
        'min_content_pixels' => 2,
        'padding_points' => 12.0,
        'min_trim_ratio' => 0.03,
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

        /*
        |----------------------------------------------------------------------
        | Scattered stamps
        |----------------------------------------------------------------------
        |
        | Stamps are drawn over the page content at a very low opacity, so they
        | barely register while reading yet prove where a copied paper came
        | from. They blend with Multiply: on white paper they show as a faint
        | tint of `colour`, while black text crossing them stays fully black, so
        | they never wash out what students need to read. Drawing beneath the content would hide them behind full-page
        | scans. Each page is split into `stamps_per_page` horizontal bands and
        | every band gets one stamp at a random position and tilt, so there is
        | no fixed spot to cover up; three bands make covering them all tedious. The font size scales with the shorter side
        | of the page: 36pt on A4.
        |
        */

        'stamp' => [
            'stamps_per_page' => (int) env('PDF_WATERMARK_STAMPS_PER_PAGE', 3),
            'min_angle_degrees' => 15.0,
            'max_angle_degrees' => 65.0,
            'opacity' => (float) env('PDF_WATERMARK_OPACITY', 0.05),
            'colour' => env('PDF_WATERMARK_COLOUR', '#2F5D9B'),
            'blend_mode' => 'Multiply',
            'font_size_per_mm' => 36 / 210,
            'min_font_size' => 8.0,
            'max_font_size' => 120.0,
            'coverage' => 0.9,
        ],
    ],

    'hardening' => [
        /*
        |----------------------------------------------------------------------
        | Watermark hardening
        |----------------------------------------------------------------------
        |
        | Watermarks drawn as text are easy to strip: PDF editors delete them as
        | objects and `gs -dFILTERTEXT` removes every one of them from a scan
        | while leaving the scan untouched. These measures make that harder:
        |
        | - `outline_marks` turns the stamps' text into vector shapes, so text
        |   filters and "delete all text" tools cannot find them. The header
        |   credit stays real text, so readers can select and copy it.
        | - `bake_scanned_pages` flattens pages holding (almost) no text into a
        |   single image with the stamps painted into its pixels. The header
        |   credit is laid on top afterwards, so it stays crisp. Pages with up
        |   to `scanned_page_max_characters` characters of text count as scans,
        |   which tolerates stray labels such as a scanner app's credit.
        | - `lock_editing` encrypts the result with a random owner password that
        |   allows viewing and printing but not editing or extracting pages.
        | - Every page carries invisible trace text and the document info holds
        |   the trace ID, so a reposted copy can be traced back to its upload.
        |
        */

        'outline_marks' => (bool) env('PDF_OUTLINE_MARKS', true),
        'bake_scanned_pages' => (bool) env('PDF_BAKE_SCANNED_PAGES', true),
        'scanned_page_max_characters' => 20,
        // 150 DPI across an A4 page: crisp for printed text at a third of the bytes.
        'baked_pixel_width' => (int) env('PDF_BAKED_PIXEL_WIDTH', 1240),
        'baked_jpeg_quality' => (int) env('PDF_BAKED_JPEG_QUALITY', 60),
        'lock_editing' => (bool) env('PDF_LOCK_EDITING', true),
    ],
];
