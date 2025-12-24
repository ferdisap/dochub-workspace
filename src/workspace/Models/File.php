<?php

namespace Dochub\Workspace\Models;

use Dochub\Encryption\EncryptStatic;
use Dochub\Workspace\Blob;
use Dochub\Workspace\File as WorkspaceFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// walaupun ada banyak record file dengan path (relative_path terhadap root workspace) jika blobnya sama maka tidak akan write new blob
class File extends Model
{
  protected $table = 'dochub_files';
  protected $primaryKey = 'id';
  public $incrementing = false;
  protected $keyType = 'string';

  protected $fillable = [
    "id",
    'merge_id',
    'workspace_id',
    'relative_path',
    'blob_hash',
    'old_blob_hash', // untuk lacak perubahan
    'action',
    'size_bytes',
    'file_modified_at'
  ];

  protected $casts = [
    'file_modified_at' => 'datetime',
  ];

  protected static function booted()
  {
    static::creating(function ($model) {
      // Isi ID dengan UUID baru jika belum ada
      if (empty($model->id)) {
        $model->id = (string) Str::uuid();
      }
    });
  }

  public static function createFromWsFile(WorkspaceFile $dhFile, string $userId, int $workspaceId = 0, string | int $mergeId = 0)
  {
    $hash = $dhFile->sha256;
    $blobPath = Blob::hashPath($hash);
    $fileId = EncryptStatic::deriveFileIdBin($blobPath, (string) $userId)['str'];
    $relativePath = $dhFile->relative_path;
    $size_bytes = $dhFile->size_bytes;
    $file_modified_at = $dhFile->file_modified_at;
    while (File::where('id', $fileId)->count() > 0) {
      $fileId = Str::uuid()->toString();
    }
    $file = static::create([
      "id" => $fileId,
      'relative_path' => $relativePath,
      'merge_id' => $mergeId, // walau uuid bisa disi 0
      'blob_hash' => $hash,
      'workspace_id' => $workspaceId, // zero is nothing. Bisa saja untuk worksapce default
      //'old_blob_hash', // nullable
      // 'action' => 'upload', // walaupun fungsi ini dipakai di turunan, action tetap upload karena file asalnya adalah uploadan
      'action' => 'added', // added karena file di upload. 
      'size_bytes' => $size_bytes,
      'file_modified_at' => $file_modified_at
    ]);
    return $file;
  }

  // Relasi
  public function merge(): BelongsTo
  {
    return $this->belongsTo(Merge::class, 'merge_id');
  }

  public function workspace(): BelongsTo
  {
    return $this->belongsTo(Workspace::class, 'workspace_id');
  }

  public function blob(): BelongsTo
  {
    return $this->belongsTo(Blob::class, 'blob_hash', 'hash');
  }

  // public function oldBlob(): BelongsTo
  // {
  //   return $this->belongsTo(Blob::class, 'old_blob_hash', 'hash');
  // }

  // Scopes
  public function scopeForPath($query, string $path)
  {
    return $query->where('relative_path', $path);
  }

  public function scopeChanged($query)
  {
    return $query->where('action', '!=', 'unchanged');
  }

  // Accessors
  public function getIsBinaryAttribute(): bool
  {
    return $this->blob?->is_binary ?? false;
  }

  public function getExtensionAttribute(): string
  {
    return pathinfo($this->relative_path, PATHINFO_EXTENSION);
  }

  public function getDirectoryAttribute(): string
  {
    return dirname($this->relative_path);
  }

  // Accessor (DB → app): bin → string UUID
  // karena sudah di mutator set jadi tidak diperlukan lagi
  // public function getIdAttribute($value)
  // {
  //   if (is_string($value) && strlen($value) === 16) {
  //     return $this->formatUuidStr(bin2hex($value));
  //   }
  //   return $value;
  // }

  // Mutator: izinkan masukkan binary 16-byte ATAU UUID string
  // mengizinkan Str::uuid(), Uuid::uuid4(), atau deriveFileIdBin()["str"]/deriveFileIdBin()["bin"]
  public function setIdAttribute($value)
  {
    if (is_string($value)) {
      if (strlen($value) === 16) {
        // Binary 16-byte → convert ke UUID string dulu
        $hex = bin2hex($value);
        $this->attributes['id'] = $this->formatDeterministicUuid($hex);
      } elseif (strlen($value) === 36 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
        // UUID string valid → simpan langsung
        $this->attributes['id'] = $value;
      } else {
        throw new \InvalidArgumentException('Invalid UUID or binary input for id');
      }
    } else {
      throw new \InvalidArgumentException('id must be string (UUID or binary 16-byte)');
    }
  }

  // Helper: format UUID v4-like dari 32-char hex (seperti di TS)
  private function formatDeterministicUuid(string $hex32): string
  {
    return sprintf(
      '%s-%s-4%s-%s%s-%s',
      substr($hex32, 0, 8),
      substr($hex32, 8, 4),
      substr($hex32, 13, 3),
      dechex((hexdec($hex32[16]) & 0x3) | 0x8),
      substr($hex32, 17, 3),
      substr($hex32, 20, 12)
    );
  }
}
