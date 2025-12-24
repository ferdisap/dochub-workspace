<?php

namespace Dochub\Workspace;

use Dochub\Workspace\Helpers\Uri;
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
    public int $size_bytes,
    public int $file_modified_at,
  ) {}

  public static function create(array $fileArray): self
  {
    $relative_path = $fileArray["relative_path"];
    $sha256 = $fileArray["sha256"];
    $size_bytes = $fileArray["size_bytes"];
    $file_modified_at = $fileArray["file_modified_at"];

    return new self(
      $relative_path,
      $sha256,
      $size_bytes,
      $file_modified_at
    );
  }

  /** relative to manifest path */
  public static function path(?string $path = null)
  {
    return Workspace::filePath() . ($path ? "/{$path}" : "");
  }

  /**
   * Memastikan timestamp dalam satuan detik (Unix Timestamp).
   * Menangani milidetik, float, string koma, dan pembulatan ke bawah.
   * 
   * @param mixed $timestamp Input bisa berupa 1766569138915, "1766569138,915", atau 1766569138.915
   * @return int Timestamp dalam detik yang siap digunakan untuk touch() atau filemtime()
   */
  function ensureTimestampInSeconds(string | int $timestamp)
  {
    // 1. Normalisasi: Ubah koma menjadi titik dan pastikan menjadi tipe float/numeric
    if (is_string($timestamp)) {
      $timestamp = str_replace(',', '.', $timestamp);
    }

    $val = (float) $timestamp;

    // 2. Deteksi Dinamis: 
    // Jika angka > 100.000.000.000, hampir pasti itu adalah milidetik (13 digit).
    // Standar detik saat ini (tahun 2025) masih di kisaran 1.700.000.000 (10 digit).
    if ($val > 10000000000) {
      $val = $val / 1000;
    }

    // 3. Bulatkan ke bawah dan kembalikan sebagai integer
    return (int) floor($val);
  }

  // // --- Contoh Berbagai Skenario ---

  // // A. Input Milidetik TypeScript (Integer)
  // echo ensureTimestampInSeconds(1766569138915); 
  // // Hasil: 1766569138

  // // B. Input String dengan Koma
  // echo ensureTimestampInSeconds("1766569138,915"); 
  // // Hasil: 1766569138

  // // C. Input Detik dengan Desimal (Float)
  // echo ensureTimestampInSeconds(1766569138.915); 
  // // Hasil: 1766569138

  // // D. Input yang sudah benar (Detik)
  // echo ensureTimestampInSeconds(1766569138); 
  // // Hasil: 1766569138


  function toArray()
  {
    return [
      'relative_path' => Uri::normalizePath($this->relative_path),
      'sha256' => $this->sha256,
      'size_bytes' => (int) $this->size_bytes,
      'file_modified_at' => $this->ensureTimestampInSeconds($this->file_modified_at),
    ];
  }
}
