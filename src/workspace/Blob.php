<?php

namespace Dochub\Workspace;

use Dochub\Workspace\Enums\ManifestSource;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestVersionParser;
use Exception;
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

  public static function setLocalReadOnly(string $blobPath){
    // agar file tidak bisa di replace atau rewrite (izin baca saja)
    @chmod($blobPath, 0444); 
  }
  
  public static function setLocalReadWrite(string $blobPath){
    // Mengubah izin menjadi 0644 (read/write untuk pemilik, read-only untuk lainnya)
    @chmod($blobPath, 0644);
    // Grup (4) & Lainnya (4): Sebaiknya hanya bisa membaca file (misalnya, agar server web dapat menampilkan gambar atau file CSS kepada pengunjung).
  }

  /**
   * get hash path
   */
  public static function hashPath($hash)
  {
    $subDir = substr($hash, 0, 2);
    return self::path("{$subDir}/{$hash}");
  }

  public function store(string $userId, string $source, array $files, callable $callback) :Manifest
  {
    $processed = 0;
    
    $total_files = count($files);
    $total_size_bytes = 0; // sebelum di hash
    // $hash_tree_sha256 = null;
    // $storage_path = null;
    $wsFiles = [];
    foreach ($files as $relativePath => $filePath) {
      // dd($relativePath, $filePath, file_exists($filePath));
      try {
        if (Workspace::isDangerousFile($relativePath)) {
          $result['errors'][] = "Skipped dangerous file: {$relativePath}";
          continue;
        }
        
        $filesize = filesize($filePath);
        // $mtime = filemtime($filePath);

        // if($filePath && $mtime){
        if($filePath){
          $hash = $this->blobStorage->store($filePath);
          $blobPath = $this->blobStorage->getBlobPath($hash);
          $mtime = filemtime($blobPath);
          $processed++;
  
          $total_size_bytes += $filesize;
          $wsFiles[] = new File($relativePath, $hash, $filesize, $mtime);
          
          $callback($hash, $relativePath, $filePath, $filesize, $mtime, null, $processed, $total_files);
        } else {
          throw new Exception('Failed to save blob');
        }
      } catch (\Exception $e) {
        throw $e;
        // $callback('', $relativePath, $filePath, $e, $processed, $total_files);
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

  public function readStream(string $hash, callable $callback){
    return $this->blobStorage->readStream($hash, $callback);
  }

  public function isExist(string $hash){
    return file_exists($this->blobStorage->getBlobPath($hash));
  }

  public function destroy(string $hash){
    $blobPath = $this->blobStorage->getBlobPath($hash);
    if(file_exists($blobPath)){
      self::setLocalReadWrite($blobPath);
      return unlink($blobPath);
    }
    return false;
  }

  // public function hasBlobbed(string $filePath)
  // {
    // 1. instance blobLocalStorage class
    // 2. create hash $newfile (blobLocalStorage->resolveHash);
    // 3. check blobLocalStorage->blobExist
  // }
}
