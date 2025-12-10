<?php

namespace Dochub\Encryption\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

class EncryptionKey extends Model
{
  protected $table = 'encryption_keys';

  protected $fillable = [
    'user_id',
    'public_key',
  ];

  // Relasi
  public function user()
  {
    return $this->belongsTo(User::class, 'user_id');
  }
}
