<?php

use Dochub\Controller\ManifestController;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web', 
  'throttle:100,1' // 100 chunk/menit
  ])->group(function () {
  // deprecated karena tidak boleh langsung query manifest kecuali melalui workspace
  Route::get('/manifest/get', [ManifestController::class, 'getManifests'])->middleware('auth')->name('dochub.get.manifests');
  Route::get('/manifest/search', [ManifestController::class, 'searchManifests'])->middleware('auth')->name('dochub.search.manifest');
});