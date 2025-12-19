<?php

namespace Dochub\Workspace;

use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestVersionParser;
use Exception;

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
  public static function hashPath(string $hash)
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
        
        $mtime = filemtime($filePath); // cek lagi, $filePath seharusnya sudah di touch() dengan file aslinya saat upload atau saat lainnya
        $filesize = filesize($filePath);

        if($filePath){
          $hash = $this->blobStorage->store($filePath);
          $blobPath = $this->blobStorage->getBlobPath($hash);
          @touch($blobPath, $mtime);
          self::setLocalReadOnly($blobPath);
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
      return self::unlink($blobPath);
    }
    return false;
  }

  public static function unlink(string $path)
  {
    return @unlink($path);
  }

  public static function mimeTextList(){
    return [
    "application/javascript",
    "application/json",
    "application/xml",
    "application/xhtml+xml",
    "application/manifest+json",
    "application/ld+json",
    "application/soap+xml",
    "application/vnd.api+json",
    "application/atom+xml",
    // // walaupun docx tapi ini adalah binary karena di zip
    // "application/msword",
    // "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    // "application/vnd.ms-excel",
    // "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    // "application/vnd.ms-powerpoint",
    // "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    // "application/vnd.oasis.opendocument.text",
    "application/rss+xml",
    // "application/pkcs7-mime",
    // "application/pgp-signature",
    "application/yaml",
    "application/toml",
    "application/x-www-form-urlencoded",
    "application/pgp-signature",
    "application/pkcs7-mime",
    // "multipart/form-data",
    "image/svg+xml",
    "image/vnd.dxf",
    "model/step",
    "model/step+xml",
    // "model/step+zip",
    // "model/step-xml+zi",
    // "model/iges",
    "model/obj",
    // "model/stl",
    "model/gltf+json",
    "model/vnd.collada+xml",
  ];
  }
}
