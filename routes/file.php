<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\FileController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Dochub\Middleware\AccessWithToken;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web', 
  AccessWithToken::class,
  'throttle:100,1' // 100 chunk/menit
  ])->group(function () {
  // download uploaded file
  Route::get('/file/download/{blob:hash}', [FileController::class, 'getFile'])->name('dochub.file.get');
});