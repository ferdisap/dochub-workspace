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
   * Sama dengan di Typescript. Jika file (biner dan kecil) atau (text) maka hash full
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
    if(strlen($userId) != 64) $userId = hash('sha256', $userId, false);
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

  public static function hash(string $source):string
  {
    return hash('sha256', $source);
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

    // jika binary dan sizenya kurang dari limit (2x threshold) maka hash full
    if ($size <= $threshold * 2) {
      // konsekuensinya yaitu hash akan tetap SAMA meskipun file pernah diedit (berubah filemtime nya)
      return self::hashFileFull($absolutePath);
    } else {
      // konsekuensinya yaitu hash BERBEDA meskipun isi filenya sama karena pernah di edit sehingga filemtimenya berbeda
      return self::hashFileThreshold($absolutePath, $thresholdMB);
    }
  }

  /**
   * Hitung SHA-256 dengan streaming (RAM efisien)
   * tidak melibatkan file mtime (file_modified_at), murni isi file saja
   */
  public static function hashFileFull(string $absolutePath)
  {
    $ctx = hash_init('sha256');
    $handle = fopen($absolutePath, 'rb');
    if (!$handle) {
      throw new \RuntimeException("Gagal membuka file: $absolutePath");
    }
    try {
      while (!feof($handle)) {
        $chunk = fread($handle, 8192); // 8KB per chunk — RAM kecil
        if ($chunk === false) break;
        hash_update($ctx, $chunk);
      }
      return hash_final($ctx);
    } finally {
      fclose($handle);
    }
  }

  /**
   * Hash file threshold (RAM-friendly):
   * - Jika file <= 2MB → hash full
   * - Jika > 2MB → hash gabungan 1MB awal + 1MB akhir
   * 
   * Melibatkan filemtime agar tetap berbeda walau ada perubahan ditengah. 
   * Konsekuensinya yaitu hash akan berbeda meskipun isi filenya sama karena filemtime
   *
   * @param string $absolutePath Absolute path ke file
   * @param int $thresholdMB Threshold dalam MB (default 1)
   * @return string Hex string SHA-256 (64 karakter)
   */
  public static function hashFileThreshold(string $absolutePath, int $thresholdMB = 1): string
  {
    $threshold = $thresholdMB * 1024 * 1024; // Konversi ke bytes

    // Jika diisi false (Default): PHP hanya membersihkan cache statistik file (seperti ukuran file, mtime, izin akses/permissions). Namun, PHP tetap menyimpan lokasi jalur (path) file tersebut.
    // Jika diisi true: PHP akan menghapus cache statistik DAN memaksa sistem untuk melakukan resolusi ulang terhadap jalur file tersebut.
    // jadi dibuat true saja karena fungsi ini jarang dipanggil.
    clearstatcache(true, $absolutePath);

    $size = filesize($absolutePath);
    $mtime = filemtime($absolutePath); // Mendapatkan timestamp (integer)

    $ctx = hash_init('sha256');
    $handle = fopen($absolutePath, 'rb');
    if (!$handle) {
      throw new \RuntimeException("Gagal membuka file: $absolutePath");
    }

    try {
      // 1. Baca Bagian Head (Awal)
      $read = 0;
      while ($read < $threshold && !feof($handle)) {
        $toRead = min(8192, $threshold - $read);
        $chunk = fread($handle, $toRead);
        if ($chunk === false) break;
        hash_update($ctx, $chunk);
        $read += strlen($chunk);
      }

      // 2. Masukkan Ukuran File (Penting agar sinkron dengan verifyPartialHash)
      // mambahkan Metadata: Size (8 byte) + Mtime (8 byte)
      // Ini mencegah file dengan head/tail sama tapi size beda dianggap identik
      // tapi tetap tidak berlaku jika file sizenya identik. Misalnya file text yang ditengah-tengah diganti 1 huruf ([a-z] atau [A-Z] yang jenisnya (ASCII,dll) sama)
      // solusinya tambahkan mtime
      hash_update($ctx, pack('J', $size));
      hash_update($ctx, pack('J', $mtime));

      // 3. Baca Bagian Tail (Akhir) menggunakan fseek
      if ($size > $threshold) {
        // Lompat ke posisi: Ukuran total - threshold
        fseek($handle, max(0, $size - $threshold));

        while (!feof($handle)) {
          $chunk = fread($handle, 8192);
          if ($chunk === false) break;
          hash_update($ctx, $chunk);
        }
      }

      return hash_final($ctx);
    } finally {
      // Selalu tutup handle untuk membebaskan RAM dan resource OS
      fclose($handle);
    }
  }
}
