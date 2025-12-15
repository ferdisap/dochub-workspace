<?php

namespace Dochub\Workspace\Models;

use Dochub\Workspace\Models\Casts\TokenHash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

class AccessToken extends Model
{
  protected $table = 'dochub_token';

  protected $fillable = [
    'user_id',
    'provider',
    'access_token', // hashed di controller
    'refresh_token', // hashed di controller
    'expires_at',
    'revoked',
  ];

  protected $casts = [
    'expires_at' => 'datetime',
    'revoked' => 'boolean',
  ];

  // Relasi
  public function user()
  {
    return $this->belongsTo(User::class, 'user_id');
  }

  public function savedToken(){
    return $this->hasOne(SavedToken::class, 'token_id');
  }
}
