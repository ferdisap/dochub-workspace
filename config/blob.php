<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Chunk Size untuk Streaming I/O
    |--------------------------------------------------------------------------
    |
    | Sesuaikan berdasarkan kapasitas server:
    | - 'low'   : 8192    (8 KB) — RAM sangat terbatas
    | - 'medium': 32768   (32 KB) — default (rekomendasi)
    | - 'high'  : 262144  (256 KB) — SSD cepat, RAM cukup
    |
    */
    'chunk_size' => env('DOCHUB_WORCKSPACE_BLOB_CHUNK_SIZE', 32768),

    /*
    | Threshold untuk verifikasi partial (byte)
    */
    'partial_verify_threshold' => env('DOCHUB_WORCKSPACE_BLOB_PARTIAL_VERIFY_THRESHOLD', 50_000_000),
];