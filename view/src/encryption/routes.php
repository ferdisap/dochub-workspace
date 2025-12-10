<?php

use App\Http\Controllers\Auth\ForOAuthClientController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\OAuthClientController;
use App\Http\Middleware\PasssportCookieAuth;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

// return string
function computeStreamChecksum(string $headerWithPlaceholder, string $filePath)
{
  // 1. Mulai hasher
  $ctx = hash_init('sha256');

  // 2. Update dengan header (termasuk 32-byte placeholder nol)
  hash_update($ctx, $headerWithPlaceholder);

  // 3. Update dengan chunk — streaming, 64KB per baca
  $chunksFile = fopen($filePath, 'rb');
  while (!feof($chunksFile)) {
    $buffer = fread($chunksFile, 65536); // 64 KiB
    if ($buffer === false) break;
    hash_update($ctx, $buffer);
  }
  fclose($chunksFile);

  $checksum = hash_final($ctx, true); // jika param kedua `true` = raw binary (32 byte)
  if (strlen($checksum) !== 32) {
    throw new RuntimeException("SHA-256 output != 32 bytes");
  }

  return $checksum; // string biner 32 byte
  // bin2hex($checksum) // string hex jika $checksum adalah binary
  // hex2bin($checksum); // string binary 32 byte, jika $cheksum bukan binary
}

// binary string
function computeChecksum(string $binaryData): string
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

function verifyHashChunk(string $binaryData, string $expectedHash): bool
{
  // Langkah 3: Ubah ke hex lowercase (8 karakter)
  $computedMiniHash = bin2hex(computeChecksum($binaryData));
  return hash_equals($computedMiniHash, $expectedHash);
}

// string biasa, bukan binary
function hashFileThreshold(string $filePath, int $thresholdMB = 1)
{
  $size = filesize($filePath);
  $threshold = $thresholdMB * 1024 * 1024;

  if ($size <= $threshold * 2) {
    return hash_file('sha256', $filePath);
  }

  $first = file_get_contents($filePath, false, null, 0, $threshold);
  $last  = file_get_contents($filePath, false, null, max(0, $size - $threshold), $threshold);

  return hash('sha256', $first . $last);
}

function basePath()
{
  return storage_path("uploads");
}

function metaJsonPath(string $fileId)
{
  return basePath() . "/meta/{$fileId}.json";
}

function baseChunkPath()
{
  return basePath() . "/chunks";
}

function chunkPath(string $fileId, int $index)
{
  return baseChunkPath() . "/" . "{$fileId}-index{$index}.bin";
}

function encryptedName(string $fileId)
{
  return "{$fileId}.fnc";
}

function encryptedPath(string $fileId)
{
  return baseChunkPath() . "/" . encryptedName($fileId);
}

function encryptedPathByFilename(string $filename)
{
  return baseChunkPath() . "/" . "{$filename}";
}

function mergedChunkPath(string $fileId)
{
  return baseChunkPath() . "/{$fileId}.bin";
}

function encryptedTmpPath(string $fileId)
{
  return encryptedPath($fileId) . ".tmp";
}

// tes upload encrypt
Route::get('/upload-encrypt', function () {
  return view('upload-encrypt.encrypt');
});

// cek dahulu apakah sudah ada file dengan id itu agar tidak appended;
Route::post('/upload-encrypt-start', function (Request $request) {
  // Logika Anda di sini:
  // - Validasi request awal
  // - Catat fileId baru di database, dll.
  $fileId = $request->header('X-File-Id');

  // check apakah fileId sudah ada di disk atau belum


  $outputPath = metaJsonPath($fileId);
  $metaJson = $request->getContent(); // json;
  @mkdir(storage_path("uploads/meta"), 0755, true);
  $metaSize = file_put_contents($outputPath, $metaJson);

  if (!$metaSize) return response(null, 400);

  // $totalChunks = $request->header('X-Total-Chunks');
  // $signedUrls = [];
  // // Loop untuk membuat URL bertanda tangan untuk setiap chunk
  // for ($i = 0; $i < $totalChunks; $i++) {
  //   // Kita gunakan temporarySignedRoute agar URL kedaluwarsa setelah 15 menit
  //   $url = URL::temporarySignedRoute(
  //     'upload.chunk',
  //     now()->addMinutes(15),
  //     [
  //       'fileId' => $fileId,
  //       'index' => $i,
  //       // Tambahkan query param tambahan jika perlu, misal 'userId' => auth()->id()
  //     ]
  //   );
  //   $signedUrls[] = $url;
  // }

  return response()->json([
    'fileId' => $fileId,
    // 'urls' => $signedUrls, // Mengembalikan array URL lengkap ke frontend
  ]);
});

// routes/api.php
Route::put('/upload-encrypt-chunk', function (Request $request) {
  // Validasi
  // if (!$request->hasValidSignature()) abort(403);

  $fileId = $request->header('X-File-Id');
  $index = $request->header('X-Chunk-Index');

  // 1. Validasi header
  $expectedHash = $request->header('X-Chunk-Hash');
  if (!$expectedHash || !ctype_xdigit($expectedHash) || strlen($expectedHash) !== 8) {
    return response()->json(['error' => 'Missing or invalid X-Chunk-Hash'], 400);
  }
  // atau
  // if (!preg_match('/^[a-f0-9]{8}$/', $expectedHash)) {
  //   return response()->json(['error' => 'Invalid X-Chunk-Hash'], 400);
  // }
  // atau 
  // ✅ Pastikan bukan JSON/base64
  // if (str_starts_with($binaryData, '{') || str_starts_with($binaryData, 'ey')) {
  //   return response()->json(['error' => 'Expected binary, got JSON/base64'], 400);
  // }

  // 2. validasi is exist in storage
  if (file_exists(chunkPath($fileId, (int) $index))) {
    return response()->json([
      "error" => "Chunk {$index} of {$fileId} exist"
    ], 409);
  }


  // 3. Validasi panjang (opsional, tapi sangat disarankan), jika index di akhir tidak di validasi atau total chunk cuma 1
  $body = $request->getContent(); // raw string /  binary
  $meta = json_decode(file_get_contents(metaJsonPath($fileId)), true); // array
  if (($meta["total_chunks"] > 1) && $index < ($meta["total_chunks"] - 1)) {
    $expectedChunkLen = $meta['chunk_size'] + 16; // cipher + 16B tag
    $bodyLen = strlen($body);
    if ($index === $meta['total_chunks'] - 1) {
      // chunk terakhir bisa lebih pendek
      if ($bodyLen < 16 || $bodyLen > $expectedChunkLen) {
        return response()->json(['error' => "Invalid last chunk size"], 400);
      }
    } else {
      if ($bodyLen !== $expectedChunkLen) {
        // Log::info("Chunk size mismatch", [
        //   "body_len" => $bodyLen,
        //   "expected_len" => $expectedChunkLen,
        // ]);
        return response()->json(['error' => "Chunk size mismatch"], 400);
      }
    }
  }

  // #4. Validasi hash,✅ Hitung hash di PHP — hasilnya HARUS SAMA dengan frontend
  $equal = verifyHashChunk($body, $expectedHash);
  if (!$equal) {
    Log::warning("Chunk {$index} hash mismatch");
    return response()->json([
      'error' => 'Chunk integrity check failed'
    ], 422);
  }

  // 6. Simpan jika valid
  // nanti harus menghindari race condition
  @mkdir(baseChunkPath(), 0755, true);
  file_put_contents(chunkPath($fileId, $index), $body, FILE_APPEND);
  Cache::put($expectedHash, $expectedHash, now()->addHours(24)); // expire 24 jam, harusnya/sebaiknya sama dengan url temporary

  return response()->json(['status' => 'ok']);
})->name("upload.chunk");

Route::post('/upload-encrypt-process', function (Request $request) {
  $fileId = $request->header('X-File-Id');
  $meta = json_decode(file_get_contents(metaJsonPath($fileId)), true); // array
  $mergedChunkFilePath = mergedChunkPath($fileId);
  if (((int)$meta["total_chunks"] > 1)) {
    for ($i = 0; $i < (int)$meta["total_chunks"]; $i++) {
      $sourcePath = chunkPath($fileId, $i);
      $source = fopen($sourcePath, 'rb');
      $dest = fopen($mergedChunkFilePath, 'ab');
      $copied = stream_copy_to_stream($source, $dest);
      fclose($source);
      fclose($dest);
      // @unlink($sourcePath);
    }
  } else {
    // jika cuma satu chunk, maka pakai yang index ke 0
    $mergedChunkFilePath = chunkPath($fileId, 0);
  }

  // Rebuild full header tanpa checksum (untuk verifikasi)
  $magic = 'FRDI';
  $version = pack('C', 1);
  // $checksumPlaceholder = str_repeat("\0", 32);
  $fileIdBin = hex2bin(str_replace('-', '', $fileId)); // 16B binary UUID
  // $fileIdBin = hex2bin($fileId); // 16B binary UUID

  $metaJsonStr = json_encode($meta, JSON_UNESCAPED_SLASHES);
  $metaLen = pack('V', strlen($metaJsonStr)); // little-endian uint32

  // $headerWithoutChecksum = $magic . $version . $checksumPlaceholder . $fileIdBin . $metaLen . $metaJsonStr;
  $headerWithoutChecksum = $fileIdBin . $metaLen . $metaJsonStr;

  // Build checksum data tanpa magic dan version, yakni [16B]file_id + [16B]meta_len + [ 4B]meta_json + [var]semua chunk
  $computedChecksum = computeStreamChecksum($headerWithoutChecksum, $mergedChunkFilePath);

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
  $filePath = encryptedPath($fileId);
  // Gunakan nama file temporer yang berbeda
  $tempFilePath = encryptedTmpPath($fileId);
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
  rename($tempFilePath, $filePath);

  return response()->json([
    "filename" => encryptedName($fileId),
  ], 200);
});

Route::get('/download-encrypt-file/{fileId}', function (Request $request, string $fileId) {
  $filePath = encryptedPath($fileId);

  if (!file_exists($filePath)) {
    return response([
      "error" => "File not found"
    ], 404);
  }

  return response()->stream(function () use ($filePath) {
    $stream = fopen($filePath, 'rb'); // Open the file for reading
    if (! $stream) {
      abort(500, 'Unable to open file for streaming.');
    }
    while (! feof($stream)) {
      echo fread($stream, 1024 * 1024); // Read and output in 1MB chunks
    }
    fclose($stream); //
  }, 200, [
    'Content-Type' => 'application/octet-stream',
    'Content-Length' => filesize($filePath),
  ]);
});