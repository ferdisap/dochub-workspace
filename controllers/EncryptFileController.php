<?php

namespace Dochub\Controller;

use Dochub\Encryption\ChunkCombiner;
use Dochub\Encryption\EncryptStatic;
use Dochub\Upload\Cache\RedisCache;
use Dochub\Upload\EnvironmentDetector;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EncryptFileController
{
  public function viewer()
  {
    return view('encrypt.encrypt');
  }

  public function putMetaJson(Request $request)
  {
    // Logika Anda di sini:
    // - Validasi request awal
    // - Catat fileId baru di database, dll.
    $fileId = $request->header('X-File-Id');

    // check apakah fileId sudah ada di disk atau belum
    if (file_exists(EncryptStatic::metaJsonPath($fileId))) {
      $metaJson = file_get_contents(EncryptStatic::metaJsonPath($fileId));
      return response()->json([
        "error" => "Meta Json of {$fileId} exist",
        "file_id" => $fileId,
        "meta" => json_decode($metaJson, true),
      ], 409);
    }

    $outputPath = EncryptStatic::metaJsonPath($fileId);
    $metaJson = $request->getContent(); // json;
    @mkdir(EncryptStatic::baseMetaPath(), 0755, true);
    $metaSize = file_put_contents($outputPath, $metaJson);

    // validasi meta json
    if ($errorValidateMeta = $this->validateMetaJson($metaJson)) {
      return response([
        "error" => "Meta Json of {$fileId} is not valid, {$errorValidateMeta}",
        "file_id" => $fileId,
      ], 400);
    }
    if (!$metaSize) return response([
      "error" => "Meta Json of {$fileId} is not valid",
      "file_id" => $fileId,
    ], 400);


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
      'file_id' => $fileId,
      // 'urls' => $signedUrls, // Mengembalikan array URL lengkap ke frontend
    ]);
  }

  public function putChunk(Request $request)
  {
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
    if (file_exists(EncryptStatic::chunkPath($fileId, (int) $index))) {
      return response()->json([
        "error" => "Chunk {$index} of {$fileId} exist"
      ], 409);
    }


    // 3. Validasi panjang (opsional, tapi sangat disarankan), jika index di akhir tidak di validasi atau total chunk cuma 1
    $body = $request->getContent(); // raw string /  binary
    $meta = json_decode(file_get_contents(EncryptStatic::metaJsonPath($fileId)), true); // array
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
    $chunkCombiner = new ChunkCombiner($fileId);
    $equal = $chunkCombiner->verifyHashChunk($body, $expectedHash);
    if (!$equal) {
      // Log::warning("Chunk {$index} hash mismatch");
      return response()->json([
        'error' => 'Chunk integrity check failed'
      ], 422);
    }

    // 6. Simpan jika valid
    // nanti harus menghindari race condition
    @mkdir(EncryptStatic::baseChunkPath(), 0755, true);
    file_put_contents(EncryptStatic::chunkPath($fileId, $index), $body, FILE_APPEND);
    // Cache::put($expectedHash, $expectedHash, now()->addHours(24)); // expire 24 jam, harusnya/sebaiknya sama dengan url temporary

    return response()->json(['status' => 'ok']);
  }

  public function processChunks(Request $request)
  {
    $fileId = $request->header('X-File-Id');

    // 2. validasi is exist in storage
    if (file_exists(EncryptStatic::encryptedPath($fileId))) {
      return response()->json([
        "error" => "{$fileId} is exist"
      ], 409);
    }

    $combiner = new ChunkCombiner($fileId);
    if (!$combiner->combine()) {
      return response()->json([
        'error' => 'Failed to combine chunks'
      ], 422);
    }

    return response()->json([
      "filename" => EncryptStatic::encryptedName($fileId),
    ], 200);
  }

  public function stream(Request $request, string $fileId)
  {
    $filePath = EncryptStatic::encryptedPath($fileId);

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
  }

  /**
   * minimum 512Kb
   */
  private function validateMetaJson(string $metaJson)
  {
    $metaJsonArray = json_decode($metaJson, true);
    $chunkSizeMessage = (isset($metaJsonArray["chunk_size"]) && ((int)$metaJsonArray["chunk_size"] > 512)) ? "" : "chunk_size is not valid";
    if ($chunkSizeMessage) return $chunkSizeMessage;
    $totalChunksMessage = (isset($metaJsonArray["total_chunks"]) && ((int)$metaJsonArray["total_chunks"] > 0)) ? "" : "total_chunks is not valid";
    if ($totalChunksMessage) return $totalChunksMessage;
    $nonceMessage = ((isset($metaJsonArray["nonce_base"]) && (bool)$metaJsonArray["nonce_base"]) && EncryptStatic::base64ToBytes($metaJsonArray["nonce_base"]) === 4) ? "" : "nonce_base is not valid";
    if ($nonceMessage) return $nonceMessage;
    $encryptedSymKeysMessage = (isset($metaJsonArray["encrypted_sym_keys"]) && count($metaJsonArray["encrypted_sym_keys"]) > 0) ? "" : "encrypted_sym_keys is not valid";
    if ($encryptedSymKeysMessage) return $encryptedSymKeysMessage;
    $ownerPubKeyMessage = ((isset($metaJsonArray["owner_pub_key"]) && (bool)$metaJsonArray["owner_pub_key"]) && EncryptStatic::base64ToBytes($metaJsonArray["owner_pub_key"]) === 4) ? "" : "owner_pub_key is not valid";
    if ($ownerPubKeyMessage) return $ownerPubKeyMessage;

    $originalMessage = "";
    if (isset($metaJsonArray["original"])) {
      if (
        !(isset($metaJsonArray["original"]["filename"]) &&
          isset($metaJsonArray["original"]["mime"]) &&
          isset($metaJsonArray["original"]["size"]))
      ) {
        $originalMessage = "original is not valid";
      }
    } else {
      $originalMessage = "original is not valid";
    }
    if ($originalMessage) return $originalMessage;
  }
}
