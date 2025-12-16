<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web', 
  'throttle:100,1' // 100 chunk/menit
  ])->group(function () {
  // download uploaded file
  Route::get('/file/download/{blob:hash}', [UploadController::class, 'getFile'])->middleware('auth')->name('dochub.file.get');
  // delete uploaded file
  Route::post('/file/delete/{manifest:hash_tree_sha256}/{blob:hash}', [UploadController::class, 'deleteFile'])->middleware('auth')->withoutScopedBindings()->name('dochub.file.delete'); // harus dipasang withoutScopedBindings() karena ada dua model tanpa saling berhubungan langsung
});