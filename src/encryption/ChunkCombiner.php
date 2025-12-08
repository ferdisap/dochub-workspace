<?php

namespace Dochub\Encryption;

class ChunkCombiner
{
  protected string $filePath;
  protected string $fileId;

  public function __construct(string $fileId)
  {
    $this->fileId = $fileId;
    $this->filePath = EncryptStatic::encryptedPath($this->fileId);
  }

  public function combine():bool
  {
    $meta = json_decode(file_get_contents(EncryptStatic::metaJsonPath($this->fileId)), true); // array
    $mergedChunkFilePath = EncryptStatic::metaJsonPath($this->fileId);
    if (((int)$meta["total_chunks"] > 1)) {
      for ($i = 0; $i < (int)$meta["total_chunks"]; $i++) {
        $sourcePath = EncryptStatic::chunkPath($this->fileId, $i);
        $source = fopen($sourcePath, 'rb');
        $dest = fopen($mergedChunkFilePath, 'ab');
        $copied = stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);
        // @unlink($sourcePath);
      }
    } else {
      // jika cuma satu chunk, maka pakai yang index ke 0
      $mergedChunkFilePath = EncryptStatic::chunkPath($this->fileId, 0);
    }

    // Rebuild full header tanpa checksum (untuk verifikasi)
    $magic = 'FRDI';
    $version = pack('C', 1);
    // $checksumPlaceholder = str_repeat("\0", 32);
    $fileIdBin = hex2bin(str_replace('-', '', $this->fileId)); // 16B binary UUID
    // $fileIdBin = hex2bin($this->fileId); // 16B binary UUID

    $metaJsonStr = json_encode($meta, JSON_UNESCAPED_SLASHES);
    $metaLen = pack('V', strlen($metaJsonStr)); // little-endian uint32

    // $headerWithoutChecksum = $magic . $version . $checksumPlaceholder . $fileIdBin . $metaLen . $metaJsonStr;
    $headerWithoutChecksum = $fileIdBin . $metaLen . $metaJsonStr;

    // Build checksum data tanpa magic dan version, yakni [16B]file_id + [16B]meta_len + [ 4B]meta_json + [var]semua chunk
    $computedChecksum = $this->computeStreamChecksum($headerWithoutChecksum, $mergedChunkFilePath);

    // Baca checksum dari header (posisi 5–36)
    // — tapi kita belum punya header penuh.
    // Solusi: ambil dari chunk 0 + metadata → bangun header sementara
    // Dalam praktik, frontend **harus kirim header penuh + metadata di awal**
    // Alternatif: frontend kirim header terpisah di `/init-upload`
    // ✅ Untuk simplifikasi, anggap frontend kirim header penuh sekali di awal (terpisah)
    // ⚠️ Untuk MVP: skip verifikasi checksum di backend dulu (dilakukan di frontend saat download)
    // Atau: kirim header terpisah via `/init-upload`
    // Simpan file .fnc
    $finalHeader = $magic . $version . $computedChecksum . $fileIdBin . $metaLen . $metaJsonStr;
    // Gunakan nama file temporer yang berbeda
    $tempFilePath = EncryptStatic::encryptedTmpPath($this->fileId);
    // 1. Tulis header ke file temp DULUAN
    file_put_contents($tempFilePath, $finalHeader);
    // 2. Buka file temp untuk DITAMBAH (append/ab)
    $dest = fopen($tempFilePath, 'ab');
    // 3. Buka file asli untuk DIBACA (rb)
    $source = fopen($mergedChunkFilePath, 'rb');
    // 4. Salin konten dari file asli ke file temp
    stream_copy_to_stream($source, $dest);
    // 5. Tutup handle
    fclose($dest);
    fclose($source);
    // 6. Hapus file asli (opsional) dan ganti nama file temp menjadi file akhir
    // unlink($originalFilePath); 
    rename($tempFilePath, $this->filePath);
    return file_exists($this->filePath);
  }

  // return string
  public function computeStreamChecksum(string $headerWithPlaceholder)
  {
    // 1. Mulai hasher
    $ctx = hash_init('sha256');

    // 2. Update dengan header (termasuk 32-byte placeholder nol)
    hash_update($ctx, $headerWithPlaceholder);

    // 3. Update dengan chunk — streaming, 64KB per baca
    $chunksFile = fopen($this->filePath, 'rb');
    while (!feof($chunksFile)) {
      $buffer = fread($chunksFile, 65536); // 64 KiB
      if ($buffer === false) break;
      hash_update($ctx, $buffer);
    }
    fclose($chunksFile);

    $checksum = hash_final($ctx, true); // jika param kedua `true` = raw binary (32 byte)
    if (strlen($checksum) !== 32) {
      throw new \RuntimeException("SHA-256 output != 32 bytes");
    }

    return $checksum; // string biner 32 byte
    // bin2hex($checksum) // string hex jika $checksum adalah binary
    // hex2bin($checksum); // string binary 32 byte, jika $cheksum bukan binary
  }

  // binary string
  public function computeChecksum(string $binaryData): string
  {
    // Langkah 1: SHA-256 → 32-byte binary
    $fullHash = hash('sha256', $binaryData, true); // true = raw binary
    if (strlen($fullHash) !== 32) {
      throw new \RuntimeException('SHA-256 output != 32 bytes');
    }
    // Langkah 2: Ambil 4 byte pertama
    return substr($fullHash, 0, 4); // string 4-byte
    // // Langkah 3: Ubah ke hex lowercase (8 karakter)
    // return bin2hex($miniHash); // lowercase, 8 char
  }

  public function verifyHashChunk(string $binaryData, string $expectedHash): bool
  {
    // Langkah 3: Ubah ke hex lowercase (8 karakter)
    $computedMiniHash = bin2hex($this->computeChecksum($binaryData));
    return hash_equals($computedMiniHash, $expectedHash);
  }

  // string biasa, bukan binary
  public function hashFileThreshold(int $thresholdMB = 1)
  {
    $size = filesize($this->filePath);
    $threshold = $thresholdMB * 1024 * 1024;

    if ($size <= $threshold * 2) {
      return hash_file('sha256', $this->filePath);
    }

    $first = file_get_contents($this->filePath, false, null, 0, $threshold);
    $last  = file_get_contents($this->filePath, false, null, max(0, $size - $threshold), $threshold);

    return hash('sha256', $first . $last);
  }
}
