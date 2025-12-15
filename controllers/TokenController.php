<?php

namespace Dochub\Controller;

use Dochub\Workspace\Models\AccessToken;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\SavedToken;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Workspace as DochubWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

class TokenController
{
  private function generateToken()
  {
    // 1. Generate 32 byte acak yang aman secara kriptografis
    $token_bytes = random_bytes(32);
    // 2. Konversi ke string heksadesimal sepanjang 64 karakter
    // $access_token_raw = bin2hex($token_bytes);
    return bin2hex($token_bytes);
  }

  private function generateExpirationTokenDate()
  {
    return now()->addHour(); // 1 jam lagi
  }

  private function saveToken(AccessToken $token, string $access_token_raw, ?string $refresh_token_raw = null)
  {
    if($token->provider === env('APP_URL')){
      $arr = [
        'token_id' => $token->id,
        'user_id' => $token->user_id,
        'access_token' => $access_token_raw,
        'expires_at' => $token->expires_at,
      ];
      if($refresh_token_raw){
        $arr['refresh_token'] = $refresh_token_raw;
      }
      SavedToken::updateOrCreate($arr);
    }
  }

  public function deleteToken(Request $request, AccessToken $token)
  {
    $validated = $request->validate([
      "deleted" => "required|boolean",
    ]);
    if ($validated["deleted"]) {
      $savedToken = $token->savedToken;

      $token->delete();

      if($savedToken){
        $savedToken->delete();
      }

      if ($token->user) {
        $token->user->makeHidden(["id", "email_verified_at", "created_at", "updated_at"]); // only name and email
      }
      $token->makeHidden(['user_id', 'created_at',]);

      return response()->json([
        'message' => "One token has been deleted from database.",
        'token' => $token
      ]);
    } else {
      abort('400');
    }
    return response()->json([
      "token" => $token,
      // "tokens" => AccessToken::where('user_id', $request->user()->id)->get(['id', 'provider', 'access_token', 'revoked', 'expires_at']),
    ],500);
  }
  public function listToken(Request $request)
  {
    return response()->json([
      "tokens" => AccessToken::where('user_id', $request->user()->id)->get(['id', 'provider', 'access_token', 'revoked', 'expires_at']),
    ]);
  }
  public function listSavedToken(Request $request)
  {
    return response()->json([
      "tokens" => SavedToken::where('user_id', $request->user()->id)->get(['id', 'access_token', 'refresh_token', 'expires_at']),
    ]);
  }

  public function createToken(Request $request)
  {
    $request->validate([
      'provider' => 'required|url',
    ]);

    $provider = $request->input('provider');

    // validate is current user has token or not
    $existing_token = AccessToken::where('user_id', $request->user()->id)->where('provider', $provider)->first(['id']);
    if($existing_token){
      return response()->json('Token existed',403); // forbidden to re create token
    }

    // --- Proses Pembuatan Token Aman ---
    // 1. Generate 32 byte acak yang aman secara kriptografis
    $access_token_raw = $this->generateToken();
    // 2. add expire
    $access_token_expires = $this->generateExpirationTokenDate();
    // 3. Generate Refresh Token (Berlaku lama) ---
    $refresh_token_raw = $this->generateToken(); // Refresh token tidak perlu disimpan di DB, kita simpan hashnya saja untuk validasi.
    // record to DB
    $tokenRecord = AccessToken::create([
      'user_id' => $request->user()->id,
      'provider' => $provider ? $provider : env('APP_URL'),
      'access_token' => hash('sha256', $access_token_raw),
      'refresh_token' => hash('sha256', $refresh_token_raw),
      'revoked' => false,
      'expires_at' => $access_token_expires,
    ]);

    // save token
    $this->saveToken($tokenRecord, $access_token_raw, $refresh_token_raw);
    
    return response()->json([
      "token" => [
        'id' => $tokenRecord->id,
        'provider' => $provider ? $provider : env('APP_URL'),
        'access_token' => $access_token_raw,
        'refresh_token' => $refresh_token_raw,
        'expires_at' => $access_token_expires->toDateTimeString(),
      ]
    ]);
  }

  public function refreshToken(Request $request)
  {
    $refresh_token_raw = $request->input('refresh_token');

    if (!$refresh_token_raw) {
      return response()->json(['error' => 'Refresh token diperlukan'], 401);
    }

    $hashed_refresh_token = hash('sha256', $refresh_token_raw);

    // Cari user berdasarkan refresh token
    $recordToken = AccessToken::where('refresh_token', $hashed_refresh_token)->first();

    if (!$recordToken) {
      return response()->json(['error' => 'Refresh token tidak valid'], 403);
    }

    // --- Generate Access Token BARU ---
    $new_access_token_raw = $this->generateToken();
    $new_access_token_expires = $this->generateExpirationTokenDate();

    // Simpan hash token BARU dan waktu kedaluwarsa BARU di DB
    $recordToken->access_token = hash('sha256', $new_access_token_raw);
    $recordToken->expires_at = $new_access_token_expires;
    $recordToken->save();

    // save token
    $this->saveToken($recordToken, $new_access_token_raw);

    return response()->json([
      'message' => 'Access token baru telah dibuat.',
      'access_token' => $new_access_token_raw,
      'expires_at' => $new_access_token_expires->toDateTimeString(),
      // 'token_type' => 'Bearer',
    ]);
  }

  public function revokeToken(Request $request)
  {
    $request->validate([
      "revoked" => "boolean",
    ]);
    if (($request->input('revoke') === true || $request->input('revoke') == '1') && ($id = $request->input('id'))) {
      $access_token_record = AccessToken::findOrFail($id);
      $access_token_record->revoke = true;
      $access_token_record->save();
      return response()->json([
        'message' => 'Dochub access token revoked'
      ]);
    } else if (($request->input('revoke') === false || $request->input('revoke') == '0') && ($id = $request->input('id'))) {
      $access_token_record = AccessToken::findOrFail($id);
      $access_token_record->revoke = false;
      $access_token_record->save();
      return response()->json([
        'message' => 'Dochub access token unrevoked'
      ]);
    }
    return response(null, 302);
  }
}
