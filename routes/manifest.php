<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web', 
  'throttle:100,1' // 100 chunk/menit
  ])->group(function () {
  Route::get('/manifest', [UploadController::class, 'getManifest'])->middleware('auth')->name('dochub.manifest');
});