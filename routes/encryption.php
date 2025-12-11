<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Illuminate\Support\Facades\Route;

Route::prefix('dochub')->middleware([
  'web',
  'throttle:100,1'
])->group(function(){
  // Route::get('/upload-encrypt', [EncryptFileController::class, 'viewer'])->middleware(['auth']);
  Route::post('/encryption/upload-start', [EncryptFileController::class, 'putMetaJson'])->middleware(['auth']);
  Route::put('/encryption/upload-chunk', [EncryptFileController::class, 'putChunk'])->middleware(['auth']);
  Route::post('/encryption/upload-process', [EncryptFileController::class, 'processChunks'])->middleware(['auth']);
  Route::get('/encryption/download-file/{fileId}', [EncryptFileController::class, 'stream'])->middleware(['auth']);
  // key
  Route::get('/encryption/get/user', [EncryptFileController::class, 'getUser'])->middleware(['auth']);
  Route::get('/encryption/get/users', [EncryptFileController::class, 'getUsers'])->middleware(['auth']);
  Route::get('/encryption/get/public-key', [EncryptFileController::class, 'getPublicKey'])->middleware(['auth']);
  Route::get('/encryption/register/public-key', [EncryptFileController::class, 'registrationView'])->middleware([ 'auth']);
  Route::post('/encryption/register/public-key', [EncryptFileController::class, 'registerPublicKey'])->middleware(['auth']);
});