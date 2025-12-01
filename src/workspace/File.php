<?php

namespace Dochub\Workspace;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Dochub\Workspace\Workspace;

class File
{
  // public string $repository_id;
  // public string $from_id;
  // public string $source;
  // public string $version;
  // public string $total_files;
  // public string $total_size_bytes;
  // public string $hash_tree_sha256;
  // public string $storage_path;

  public function __construct(
    public string $relative_path,
    public string $sha256, // $blob_hash
    public string $size_bytes,
    public string $file_modified_at,
  ) {}

  /** relative to manifest path */
  public static function path(?string $path = null)  {
    return Workspace::filePath() . ($path ? "/{$path}" : "");
  }

  function toArray()
  {
    return [
      'relative_path' => $this->relative_path,
      'sha256' => $this->sha256,
      'size_bytes' => $this->size_bytes,
      'file_modified_at' => $this->file_modified_at,
    ];
  }

}
