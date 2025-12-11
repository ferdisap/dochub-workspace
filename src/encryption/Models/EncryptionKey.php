<?php

namespace Dochub\Encryption\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

class EncryptionKey extends Model
{
  protected $table = 'dochub_encryption_keys';

  protected $fillable = [
    'user_id',
    'public_key',
  ];

  protected $casts = [
    'public_key' => 'encrypted',
];

  // Relasi
  public function user()
  {
    return $this->belongsTo(User::class, 'user_id');
  }
}
