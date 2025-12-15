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
  Route::post('/token/create', [TokenController::class, 'createToken']);
  Route::get('/token/list', [TokenController::class, 'listToken']);
  Route::get('/token/list-saved', [TokenController::class, 'listSavedToken']);
  Route::post('/token/{token}/delete', [TokenController::class, 'deleteToken'])->middleware([EnsureAccountPasswordIsMatched::class]);
  Route::post('/token/revoke', [TokenController::class, 'revokeToken'])->middleware([EnsureAccountPasswordIsMatched::class]);
});

Route::prefix('dochub')->middleware([
  AccessWithToken::class,
  'throttle:100,1' // 100 chunk/menit
])->group(function () {
  Route::post('/token/refresh', [TokenController::class, 'refreshToken']);
});
