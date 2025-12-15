<?php

namespace Dochub\Middleware;

use Closure;
use Dochub\Workspace\Models\AccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class AccessWithToken
{
  /**
   * Handle an incoming request.
   *
   * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
   */
  public function handle(Request $request, Closure $next): Response
  {
    // jika pakai bearer
    // $authHeader = $request->header('Authorization');
    // if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
    //     return response()->json(['message' => 'Unauthenticated'], 401);
    // }
    // $raw_token = substr($authHeader, 7); // ambil plaintext token

    // cara set $raw_tokendi Header
    // $access_token = AuthToken::where('user_id', $userId)->first();
    // $accessToken = $record->access_token; 
    // return response()->json(['logged_in' => true])
    // ->cookie(
    //     'access_token',
    //     $raw_token,
    //     60 * 24,                    // minutes
    //     '/',
    //     null,
    //     true,                       // secure
    //     true,                       // http-only
    //     false,
    //     'Strict'
    // );
    // atau
    // $response = Http::withToken($accessToken)->get($url); // jika ingin nembak lewat backend pakai bearer
    // $response = Http::withHeaders(['X-Dochub-Token' => $accessToken,])->get($url); // pakai X-Dochub-Token


    // Ambil token dari header
    $raw_token = $request->header('X-Dochub-Token') ?? $request->cookie('x-dochub-token');
    
    if (! $raw_token) {
      return response()->json(['message' => 'Unauthenticated'], 401);
    }

    // Hash token untuk dibandingkan dengan token_hash di DB
    // $hashed = hash('sha256', $raw_token);
    $hashed_token_from_client = hash('sha256', $raw_token);

    // Cari user/token record
    $record = AccessToken::where('access_token', $hashed_token_from_client)->first();

    if (! $record) {
      return response()->json(['message' => 'Invalid token'], 401);
    }

    // === CEK PROVIDER
    if($record->provider != env('APP_URL')){
      return response()->json(['message' => 'Invalid provider'], 401);
    }

    // === CEK REVOCATION ===
    if ($record->revoked || $record->revoked == '1') {
      return response()->json(['message' => 'Token revoked'], 403);
    }
    
    // === CEK EXPIRED ===
    // atau jika kurang dari 5 menit ($record->expires_at->diffInMinutes(now()) < 5) { do refresh token }
    if ($record->expires_at->isPast()) {
      return response()->json(['error' => 'Token expired'], 401);
    }

    // Tambahan opsional: menaruh user di request
    $request->setUserResolver(fn() => $record->user);
    // $request->merge([
    //   'access_token_hashed' => $hashed_token_from_client,
    //   'access_token_record' => $record,
    // ]);

    // Optional: attach user ke request
    // if ($record->user) {auth()->login($record->user);}

    return $next($request);
  }
}
