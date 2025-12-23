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
  // pada dasarnya file tidak bisa di delete atau diubah isinya kecuali bikin blob baru, lalu merge ke workspace
  // jadi tidak ada route delete, update
  // kecuali file uploadan yang tidak ada merge nya

  // delete uploaded file
  Route::post('/file/delete/{manifest:hash_tree_sha256}/{blob:hash}', [FileController::class, 'deleteFile'])->middleware('auth')->withoutScopedBindings()->name('dochub.file.delete'); // harus dipasang withoutScopedBindings() karena ada dua model tanpa saling berhubungan langsung
});