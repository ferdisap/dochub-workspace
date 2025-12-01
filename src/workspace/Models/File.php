<?php

namespace Dochub\Workspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// walaupun ada banyak record file dengan path (relative_path terhadap root workspace) jika blobnya sama maka tidak akan write new blob
class File extends Model
{
  protected $table = 'dochub_files';

  protected $fillable = [
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

  public function oldBlob(): BelongsTo
  {
    return $this->belongsTo(Blob::class, 'old_blob_hash', 'hash');
  }

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
}
