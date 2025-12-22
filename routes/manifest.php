<?php

use Dochub\Controller\ManifestController;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web', 
  'throttle:100,1' // 100 chunk/menit
  ])->group(function () {
  Route::get('/manifest/get', [ManifestController::class, 'getManifests'])->middleware('auth')->name('dochub.get.manifests');
  Route::get('/manifest/search', [ManifestController::class, 'searchManifest'])->middleware('auth')->name('dochub.search.manifest');
});