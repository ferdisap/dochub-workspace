<?php

namespace Dochub\Workspace;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Dochub\Workspace\Workspace;

/**
 * saat ini belum ada fungsi untuk membuat class ini berdasarkan file storage / model database
 */
class Manifest
{
  public string $hash_tree_sha256;

  protected string $storagePath = ''; // jika tidak ada maka berarti belum dibuat

  public string | null $tags = null;

  /**
   * @param string
   * @param string
   * @param int
   * @param int
   * @param Dochub\Workspace\File[]
   */
  public function __construct(
    public string $source,
    public string $version,
    public int $total_files,
    public int $total_size_bytes,
    public array $files,
  ) {
    $this->hash_tree_sha256 = self::hash($this->files);
  }

  public static function create(array $manifestArray) {
    $source = $manifestArray["source"];
    $version = $manifestArray["version"];
    $total_files = $manifestArray["total_files"];
    $total_size_bytes = $manifestArray["total_size_bytes"];
    $files = array_map(function($file) {
      return File::create($file);
    }, $manifestArray["files"]);

    return new self(
      $source,
      $version,
      $total_files,
      $total_size_bytes,
      $files,
    );
  }

  /**
   * @return string path relative to workspace/manifest
   */
  public function store() :string
  {
    $manifestLocalStorage = new ManifestLocalStorage();
    $this->storagePath = $manifestLocalStorage->store($this);
    return $this->storagePath;
  }

  /** 
   * get absolute path
   * relative to manifest path
   */
  public static function path(?string $path = null): string
  {
    return Workspace::manifestPath() . ($path ? "/{$path}" : "");
  }

  /**
   * get relative hash path
   */
  public static function hashPath($hash)
  {
    $subDir = substr($hash, 0, 2);
    return self::path("{$subDir}/{$hash}");
  }
  public static function hashPathRelative($hash)
  {
    $subDir = substr($hash, 0, 2);
    return "{$subDir}/{$hash}";
  }

  /**
   * relative path
   */
  public function storage_path(){
    return $this->storagePath;
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

  public function toArray(){
    return [
      'source' => $this->source,
      'version' => $this->version,
      'total_files' => $this->total_files,
      'total_size_bytes' => $this->total_size_bytes,
      'hash_tree_sha256' => $this->hash_tree_sha256,
      'tags' => $this->tags,
      'files' => array_map(function($file) {
        return $file->toArray();
      },$this->files),
    ];
  }

  public function __toArray(){
    return $this->toArray();
  }
}
