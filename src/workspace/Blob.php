<?php

namespace Dochub\Workspace;

use Dochub\Workspace\Enums\ManifestSource;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestVersionParser;
use Illuminate\Support\Facades\Config;

use function Illuminate\Support\now;

class Blob
{
  protected BlobLocalStorage $blobStorage;

  public function __construct()
  {
    $this->blobStorage = app(BlobLocalStorage::class);
  }

  /** relative to blob path */
  public static function path(?string $path = null): string
  {
    return Workspace::blobPath() . ($path ? "/{$path}" : "");
  }

  /**
   * get hash path
   */
  public static function hashPath($hash)
  {
    $subDir = substr($hash, 0, 2);
    return self::path("{$subDir}/{$hash}");
  }

  // public function store(array $files, callable $callback) :Manifest
  public function store(string $userId, string $source, array $files, callable $callback) :Manifest
  {
    $processed = 0;
    
    $total_files = count($files);
    $total_size_bytes = 0; // sebelum di hash
    // $hash_tree_sha256 = null;
    // $storage_path = null;

    $wsFiles = [];
    foreach ($files as $relativePath => $filePath) {
      // dd($relativePath, $filePath);
      try {
        if (Workspace::isDangerousFile($relativePath)) {
          $result['errors'][] = "Skipped dangerous file: {$relativePath}";
          continue;
        }
        $hash = $this->blobStorage->store($filePath);
        $processed++;
  
        $filesize = filesize($filePath);
        $total_size_bytes += $filesize;

        $wsFiles[] = new File($relativePath, $hash, $filesize, filemtime($filePath));
        
        $callback($hash, $relativePath, $filePath, null, $processed, $total_files);
      } catch (\Exception $e) {
        $callback('', $relativePath, $filePath, $e, $processed, $total_files);
      }
    } 

    $wsFiles = array_map(fn ($wsFile) => $wsFile->toArray(), $wsFiles);
    $version = ManifestVersionParser::makeVersion();
    $wsManifest = new Manifest(
      $source, $version, $total_files, $total_size_bytes, $wsFiles,
    );
    $wsManifest->store();
    return $wsManifest;
  }

  // public function hasBlobbed(string $filePath)
  // {
    // 1. instance blobLocalStorage class
    // 2. create hash $newfile (blobLocalStorage->resolveHash);
    // 3. check blobLocalStorage->blobExist
  // }
}
