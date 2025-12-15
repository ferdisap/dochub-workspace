<?php

use Illuminate\Support\Facades\Storage;

return [
    /*
    |--------------------------------------------------------------------------
    | Expiration Access Token 
    |--------------------------------------------------------------------------
    | in minutes
    */
    'expiration' => 60,

    /*
    |--------------------------------------------------------------------------
    | Save generated Access Token 
    |--------------------------------------------------------------------------
    | all => semua token di save
    | none => tidak ada token yang di save
    | first-client => hanya jika APP url sama dengan provider
    | third-client => hanya jika APP url tidak sama dengan provider
    */
    'save-mode' => 'all',
];