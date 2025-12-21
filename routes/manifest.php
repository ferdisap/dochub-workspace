<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web', 
  'throttle:100,1' // 100 chunk/menit
  ])->group(function () {
  Route::get('/manifest/get', [UploadController::class, 'getManifests'])->middleware('auth')->name('dochub.get.manifests');
  Route::get('/manifest/get/{manifest:hash_tree_sha256}', [UploadController::class, 'getManifest'])->middleware('auth')->name('dochub.get.manifest');
});