<?php

namespace Dochub\Workspace;

use Dochub\Encryption\EncryptStatic;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Dochub\Workspace\Workspace;

// {
//   "source": "...",
//   "version": "...",
//   "total_files": 128,
//   "total_size_bytes": 4567890, // file asli
//   "hash_tree_sha256": "...", //  diambil dari prop["files"]
//   // files adalah semua file yang aktiv. Jika ada perubahan blob atau path maka yang lama dikeluarkan->dipindahkan ke modified_files
//   "files": [ 
//      {
//        "path": "config/app.php",
//        "sha256": "a1b2c3...",
//        "size": 2048,
//        "file_modified_at": "2025-11-20T14:00:00Z", // mtime dari file asli sebelum jadi blob
//        "message?":"..."
//      }
//   ],
//   // history adalah semua file yang tidak terpakai, di sort berdasarkan id file database.
//   // berbeda dengan files yang tidak ada id file karena itu adalah semua file yang sedang aktif
//   // setiap file di prop["files"] tidak ada di history
//   // ada kemungkinan setiap active file akan di rollback ke history sebelumnya (sesuai index history). 
//   // jika synronizing maka ada kemungkinan "$id" berbeda jika pakai id number/incremented. Jadi solusinya pakai uuid
//   // sepertinya history tidak dipakai
//   "histories": {
//     "$id": [
//       {
//         "path" : "...",
//         "sha256": "...",
//         "size": "...",
//         "file_modified_at": "...",
//         "message?": "..."
//       }
//     ]
//   }
// }

/**
 * saat ini belum ada fungsi untuk membuat class ini berdasarkan file storage / model database
 */
class Manifest
{
  public string $hash_tree_sha256;

  protected string $storagePath = ''; // jika tidak ada maka berarti belum dibuat

  public string | null $tags = null;

  protected array $history = []; // ["$id" => [$file]]

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
    public array $files, // berisi WsFile 
    string $storage_path = '' // jika tidak ada maka berarti belum dibuat
  ) {
    $this->hash_tree_sha256 = self::hash($this->files);
    $this->storagePath = $storage_path;
  }

  public static function create(array $manifestArray, $storage_path = '')
  {
    $source = $manifestArray["source"];
    $version = $manifestArray["version"];
    $total_files = $manifestArray["total_files"];
    $total_size_bytes = $manifestArray["total_size_bytes"];
    $files = array_map(function ($file) {
      return File::create($file);
    }, $manifestArray["files"]);

    return new self(
      $source,
      $version,
      $total_files,
      $total_size_bytes,
      $files,
      $storage_path,
    );
  }

  /**
   * @return string path relative to workspace/manifest
   */
  public function store(): string
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
  /**
   * tanpa nama file
   */
  public static function hashPathRelative($hash)
  {
    $subDir = substr($hash, 0, 2);
    return "{$subDir}/{$hash}";
  }

  /**
   * relative path
   */
  public function storage_path()
  {
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
      return EncryptStatic::hash(json_encode($source));
    } else {
      $content = self::content($source);
      return EncryptStatic::hash(json_encode($content['files'] ?? []));
    }
  }

  public static function unlink(string $path)
  {
    return @unlink($path);
  }

  /**
   * Mengurutkan array objek secara in-place (hemat RAM).
   * 
   * @param array &$data Referensi ke array (menggunakan & agar tidak copy-on-write)
   * @param string $sortBy Kunci pengurutan ('size' atau 'relative_path')
   * @param string $order 'asc' atau 'desc'
   */
  private function sortFileDataInPlace(array &$data, string $sortBy, string $order = 'asc'): void
  {
    $isAsc = $order === 'asc';

    usort($data, function ($a, $b) use ($sortBy, $isAsc) {
      $valA = $a[$sortBy] ?? ($sortBy === 'size' ? 0 : '');
      $valB = $b[$sortBy] ?? ($sortBy === 'size' ? 0 : '');

      if ($valA === $valB) {
        return 0;
      }

      // Spaceship operator (<=>) sangat efisien di PHP 7+ dan PHP 8
      // Menghasilkan -1, 0, atau 1 secara otomatis
      $comparison = $valA <=> $valB;

      return $isAsc ? $comparison : -$comparison;
    });
  }

  public function ensureTotalFiles() :int
  {
    return $this->total_files = count($this->files);
  }

  public function ensureTotalSizeBytes():int
  {
    $totalSize = 0;
    foreach ($this->files as $wsFile) {
      $totalSize += $wsFile->size_bytes;
    }
    return $this->total_size_bytes = $totalSize;
  }

  // // Contoh Penggunaan:
  // $files = [
  //     ['relative_path' => 'images/logo.png', 'size' => 1500],
  //     ['relative_path' => 'docs/readme.md', 'size' => 500],
  //     ['relative_path' => 'assets/style.css', 'size' => 2000],
  // ];

  // // Mengurutkan langsung pada variabel $files (hemat RAM)
  // sortFileDataInPlace($files, 'size', 'desc');

  // print_r($files);


  public function toArray()
  {
    $files = array_map(function (File $file) {
      return $file->toArray();
    }, $this->files);

    $this->sortFileDataInPlace($files, 'size_bytes');

    return [
      'source' => $this->source,
      'version' => $this->version,
      'total_files' => $this->total_files,
      'total_size_bytes' => $this->total_size_bytes,
      'hash_tree_sha256' => $this->hash_tree_sha256,
      'tags' => $this->tags,
      'files' => $files,
      // "history" => array_walk($this->history, function (&$filesInHistory, $id) {
      //   return array_map(function ($file) {
      //     return $file->toArray();
      //   }, $filesInHistory);
      // }),
    ];
  }

  public function __toArray()
  {
    return $this->toArray();
  }
}
