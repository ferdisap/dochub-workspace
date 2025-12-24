<?php

namespace Dochub\Workspace\Models;

use Dochub\Workspace\Blob as WorkspaceBlob;
use Dochub\Workspace\Services\BlobLocalStorage;
use Illuminate\Database\Eloquent\Model;

class Blob extends Model
{
  protected $table = 'dochub_blobs';
  protected $primaryKey = 'hash'; // SHA-256
  public $incrementing = false;
  protected $keyType = 'string';

  protected $fillable = [
    'hash',
    'mime_type',
    'is_binary',
    'original_size_bytes',
    'stored_size_bytes',
    'is_stored_compressed',
    'compression_type',
    'is_already_compressed'
  ];

  protected $casts = [
    'is_binary' => 'boolean',
    'is_stored_compressed' => 'boolean',
    'original_size_bytes' => 'integer',
    'stored_size_bytes' => 'integer',
  ];

  // Relasi
  public function files()
  {
    return $this->hasMany(File::class, 'blob_hash', 'hash');
  }

  public function referencedBy()
  {
    return $this->files()->select('workspace_id', 'relative_path')
      ->distinct()
      ->get()
      ->map(fn($f) => [
        'workspace_id' => $f->workspace_id,
        'path' => $f->relative_path,
      ]);
  }

  // Accessors
  public function getIsTextAttribute(): bool
  {
    return !$this->is_binary;
  }

  public function getCompressionRatioAttribute(): float
  {
    if ($this->original_size_bytes == 0) return 0;
    return $this->stored_size_bytes / $this->original_size_bytes;
  }

  public function getSavedBytesAttribute(): int
  {
    return $this->original_size_bytes - $this->stored_size_bytes;
  }

  // Path fisik
  public function getStoragePathAttribute(): string
  {
    return WorkspaceBlob::hashPath($this->hash);
  }

  // Helper
  public function existsOnDisk(): bool
  {
    return file_exists(WorkspaceBlob::hashPath($this->hash));
  }

  /**
   * @return resource|falsee Stream yang HARUS di-fclose() oleh consumer
   */
  public function readStream(callable $callback)
  {
    return (new WorkspaceBlob)->readStream($this->hash, $callback);
  }
  
  public function getContentStream()
  {
    (new WorkspaceBlob)->readStream($this->hash, function(string $content){
      echo $content;
    }, true);
  }

  /**
   * @return resource|falsee Stream yang HARUS di-fclose() oleh consumer
   */
  public function getContent()
  {
    $path = $this->storage_path;
    if (!file_exists($path)) {
      throw new \RuntimeException("Blob not found on disk: {$this->hash}");
    }

    if ($this->original_size_bytes > (10 * 1024)) { // 10mb
      throw new \RuntimeException("File size is too big: {$this->hash}");
    }

    $content = file_get_contents($path);

    // Decompress jika perlu
    if ($this->is_stored_compressed && $this->compression_type === 'gzip') {
      return gzdecode($content); // bisa juga pakai blobStorage::decompressGzipBlob 
    }

    return $content;
  }
}
