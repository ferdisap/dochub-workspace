<?php

namespace Dochub\Encryption;

use Dochub\Workspace\Blob;

class EncryptStatic
{
  public static function basePath()
  {
    return storage_path("uploads");
  }

  public static function metaJsonPath(string $fileId)
  {
    return static::baseMetaPath() . "/{$fileId}.json";
  }

  public static function baseMetaPath()
  {
    return static::basePath() . "/meta";
  }

  public static function baseChunkPath()
  {
    return static::basePath() . "/chunks";
  }

  public static function chunkPath(string $fileId, int $index)
  {
    return static::baseChunkPath() . "/" . "{$fileId}-index{$index}.bin";
  }

  public static function encryptedName(string $fileId)
  {
    return "{$fileId}.fnc";
  }

  public static function encryptedPath(string $fileId)
  {
    return static::baseChunkPath() . "/" . static::encryptedName($fileId);
  }

  public static function encryptedPathByFilename(string $filename)
  {
    return static::baseChunkPath() . "/" . "{$filename}";
  }

  public static function mergedChunkPath(string $fileId)
  {
    return static::baseChunkPath() . "/{$fileId}.bin";
  }

  public static function encryptedTmpPath(string $fileId)
  {
    return static::encryptedPath($fileId) . ".tmp";
  }

  /**
   * Mengubah string Base64 menjadi string byte mentah.
   * Fungsi ini setara dengan menggunakan atob() dalam JavaScript/TypeScript.
   *
   * @param string $base64String String yang dienkode Base64.
   * @return string String byte biner mentah.
   */
  public static function base64ToBytes(string $base64String): string
  {
    // base64_decode melakukan pekerjaan yang sama persis dengan atob()
    return base64_decode($base64String); // $length = strlen(base64ToBytes($base64String));
  }

  /**
   * Derive fileId (16-byte binary + UUID-like string) dari file dan userId
   * Sama dengan di Typescript
   *
   * @param string $absolutePath Path file absolut
   * @param string $userId String ID user (harus ASCII tanpa `:` di akhir)
   * @return array{str: string, bin: string}
   *         - 'str': UUID v4-like format (36 char, e.g., "a1b2c3d4-e5f6-4789-9abc-def012345678")
   *         - 'bin': binary string 16-byte (bukan hex!) — cocok untuk hex2bin() atau penyimpanan blob
   */
  public static function deriveFileIdBin(string $absolutePath, string $userId): array
  {
    // 1. Hash file → hex 64 char
    $fileHashHex = self::hashFile($absolutePath);

    // 2. Deterministic input: "ferdi:v1:{userId}:{fileHashHex}"
    $input = "ferdi:v1:{$userId}:{$fileHashHex}"; // sama dengan di Typescript

    // 3. SHA-256 input → ambil 16 byte pertama
    $hashBin = hash('sha256', $input, true); // true = raw binary (32-byte)
    $fileIdBin = substr($hashBin, 0, 16); // ✅ 16-byte binary string

    // 4. Format ke UUID v4-like (bukan random, tapi struktur valid RFC4122)
    $hex = bin2hex($fileIdBin); // 32-char hex

    // RFC4122 v4: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
    // di mana:
    //   versi = 4 → posisi ke-13 = '4'
    //   variant = 10xx → posisi ke-17: (0x8-0xb), pakai bit masking
    $timeLow    = substr($hex, 0, 8);
    $timeMid    = substr($hex, 8, 4);
    $timeHiVer  = '4' . substr($hex, 13, 3); // ganti char ke-13 jadi '4'
    $clockSeqHi = dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3);
    $node       = substr($hex, 20, 12);

    $fileIdStr = implode('-', [$timeLow, $timeMid, $timeHiVer, $clockSeqHi, $node]);

    return [
      // uuid jika Tipe kolom di DB adalah CHAR(36) atau UUID (PostgreSQL)
      'str' => $fileIdStr, // "a1b2c3d4-e5f6-4789-9abc-def012345678" 36 char
      // Tipe kolom di DB adalah BINARY(16) — lebih efisien (2x lebih hemat ruang, indeks lebih cepat)
      'bin' => $fileIdBin, // ✅ \x01\x23\x45..., binary string 16-byte (bukan base64/hex)
      // karena ada mutator di model file, jadi bisa menggunakan 'bin' sebagai id database
    ];
  }

  /**
   * auto define method threshold or full
   */
  public static function hashFile(string $absolutePath)
  {
    $thresholdMB = 1; // default
    $threshold = $thresholdMB * 1024 * 1024; // bytes
    $size = filesize($absolutePath);

    if ($size === false) {
      throw new \InvalidArgumentException("File tidak ditemukan: $absolutePath");
    }

    $mime = mime_content_type($absolutePath);
    // jika binary dan sizenya kurang dari limit (2x threshold) maka hash full
    $isBinary = !(str_starts_with($mime, 'text/') || in_array($mime, Blob::mimeTextList()));
    // Jika file <= 2MB → hash full (streaming tetap, tapi sekali jalan)
    if ($isBinary && ($size <= $threshold * 2)) {
      return self::hashFileFull($absolutePath);
    } else {
      return self::hashFileThreshold($absolutePath, $thresholdMB);
    }
  }

  public static function hashFileFull(string $absolutePath)
  {
    $ctx = hash_init('sha256');
    $handle = fopen($absolutePath, 'rb');
    if (!$handle) {
      throw new \RuntimeException("Gagal membuka file: $absolutePath");
    }
    while (!feof($handle)) {
      $chunk = fread($handle, 8192); // 8KB per chunk — RAM kecil
      hash_update($ctx, $chunk);
    }
    fclose($handle);
    return hash_final($ctx);
  }

  /**
   * Hash file threshold (RAM-friendly):
   * - Jika file <= 2MB → hash full
   * - Jika > 2MB → hash gabungan 1MB awal + 1MB akhir
   *
   * @param string $absolutePath Absolute path ke file
   * @param int $thresholdMB Threshold dalam MB (default 1)
   * @return string Hex string SHA-256 (64 karakter)
   */
  public static function hashFileThreshold(string $absolutePath, int $thresholdMB = 1): string
  {
    $threshold = $thresholdMB * 1024 * 1024; // bytes
    $size = filesize($absolutePath);

    // File besar (>2MB): baca awal & akhir masing-masing $threshold byte
    $ctx = hash_init('sha256');

    // Baca awal: $threshold byte
    $handle = fopen($absolutePath, 'rb');
    if (!$handle) throw new \RuntimeException("Gagal membuka file: $absolutePath");
    $read = 0;
    while ($read < $threshold && !feof($handle)) {
      $toRead = min(8192, $threshold - $read);
      $chunk = fread($handle, $toRead);
      if ($chunk === false) break;
      hash_update($ctx, $chunk);
      $read += strlen($chunk);
    }
    fclose($handle);

    // Baca akhir: $threshold byte terakhir
    $handle = fopen($absolutePath, 'rb');
    if (!$handle) throw new \RuntimeException("Gagal membuka file: $absolutePath");
    fseek($handle, max(0, $size - $threshold)); // lompat ke posisi akhir - threshold
    while (!feof($handle)) {
      $chunk = fread($handle, 8192);
      if ($chunk !== false) {
        hash_update($ctx, $chunk);
      }
    }
    fclose($handle);

    return hash_final($ctx);
  }
}
