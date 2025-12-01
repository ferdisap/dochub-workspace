<?php

namespace Dochub\Workspace;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use Dochub\Workspace\Services\ManifestLocalStorage;

class Manifest
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
    public string $source,
    public string $version,
    public int $total_files,
    public int $total_size_bytes,
    public string $hash_tree_sha256
  ) {}

  /** relative to manifest path */
  public static function path(?string $path = null): string
  {
    return Workspace::manifestPath() . $path ? "/{$path}" : "";
  }

  /**
   * get hash path
   */
  public static function hashPath($hash)
  {
    $subDir = substr($hash, 0, 2);
    return self::path("{$subDir}/{$hash}");
  }

  public static function content(string $path)
  {
    return App::make(ManifestLocalStorage::class)->get($path);
  }

  /**
   * get hash of manifest
   * @param string berupa path fisik manifest
   * @param Array berupa files
   */
  public static function hash(string|array $source): string | false
  {
    if (!$source) return false;

    if (is_array($source)) {
      return hash('sha256', json_encode($source));
    } else {
      $content = self::content($source);
      return hash('sha256', json_encode($content['files'] ?? []));
    }
  }
}
