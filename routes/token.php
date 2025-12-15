<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\TokenController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Illuminate\Support\Facades\Route;
use Dochub\Middleware\AccessWithToken;
use Dochub\Middleware\EnsureAccountPasswordIsMatched;

Route::prefix('dochub')->middleware([
  'web',
  'auth',
  'throttle:100,1' // 100 chunk/menit
])->group(function () {
  // untuk mendapatkan token sebelum aksess dochub
  Route::get('/token/mine', [TokenController::class, 'getSavedToken']);
  // create (register) new token
  Route::post('/token/create', [TokenController::class, 'createToken']);
  // get list all token
  Route::get('/token/list', [TokenController::class, 'listToken']);
  // get list all token saved
  Route::get('/token/list-saved', [TokenController::class, 'listSavedToken']);
  // delete token
  Route::post('/token/{token}/delete', [TokenController::class, 'deleteToken'])->middleware([EnsureAccountPasswordIsMatched::class]);
  // revoking / unrevoking token
  Route::post('/token/revoke', [TokenController::class, 'revokeToken'])->middleware([EnsureAccountPasswordIsMatched::class]);
});

Route::prefix('dochub')->middleware([
  AccessWithToken::class,
  'throttle:100,1' // 100 chunk/menit
])->group(function () {
  // refresh token
  Route::post('/token/refresh', [TokenController::class, 'refreshToken']);
});
