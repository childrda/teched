<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lesson asset upload caps (kilobytes)
    |--------------------------------------------------------------------------
    |
    | Images (~5 MB) and documents (~20 MB). Raise these without a code change
    | when a district needs larger assets — but keep PHP upload_max_filesize,
    | post_max_size, reverse-proxy body limits, and Livewire's temporary
    | upload max at or above document_max_kb, or oversized files are rejected
    | before Laravel validation can explain why.
    |
    */

    'image_max_kb' => (int) env('LESSON_ASSET_IMAGE_MAX_KB', 5120),

    'document_max_kb' => (int) env('LESSON_ASSET_DOCUMENT_MAX_KB', 20480),

    /*
    |--------------------------------------------------------------------------
    | Allowed extensions (content-type validated on upload)
    |--------------------------------------------------------------------------
    |
    | SVG is intentionally absent: it can carry <script>, and files on the
    | public disk are served from the app origin — an uploaded SVG is a
    | stored XSS vector. Macro-enabled Office formats (docm/xlsm/pptm) are
    | also excluded.
    |
    */

    'image_extensions' => ['png', 'jpg', 'jpeg', 'webp', 'gif'],

    'document_extensions' => ['pdf', 'docx', 'xlsx', 'pptx'],

];
