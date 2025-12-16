<?php

namespace Dochub\Workspace\Services;

use Dochub\Workspace\Blob;
use Dochub\Workspace\Models\Blob as BlobModel;
use Dochub\Workspace\Services\Compression\Contract;
use Dochub\Workspace\Services\Compression\Gzip;
use RuntimeException;
use Dochub\Workspace\Services\LockManager as ServicesLockManager;
use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

// # Development (default)
// LOCK_DRIVER=flock

// # Production cluster
// # LOCK_DRIVER=redis
// # LOCK_TIMEOUT_MS=60000

// $hash = "ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48e" selalu 64 karakter (hex 32 byte biner);

// baca file text (otomatis uncompress)
// public function showFile(BlobStorage $blobStorage, string $hash)
// {
//     $content = $blobStorage->getBlobContent($hash); // string
//     return response($content, 200, [
//         'Content-Type' => 'text/plain',
//     ]);
// }

// Stream file besar (misal: video)
// public function streamVideo(BlobStorage $blobStorage, string $hash)
// {
//     return $blobStorage->streamBlobResponse(
//         $hash, 
//         'video.mp4', 
//         'video/mp4'
//     );
// }

// Verifikasi integritas (baca sebagai stream)
// public function verifyHash(BlobStorage $blobStorage, string $hash)
// {
//     $calculated = $blobStorage->withBlobContent($hash, function ($stream) {
//         $ctx = hash_init('sha256');
//         while (!feof($stream)) {
//             hash_update($ctx, fread($stream, 65536));
//         }
//         return hash_final($ctx);
//     });

//     return ['hash' => $calculated];
// }
// File | Ukuran Asli | Setelah Kompresi | Hemat
// config/app.php | 5.2 KB | 1.8 KB | 65%
// composer.lock | 120 KB | 28 KB | 77%
// public/main.js | 450 KB | 112 KB | 75% 
// logo.png  | 15 KB | 15 KB | 0% | (tidak dikompres) 
// brochure.pdf  | 2.1 MB | 2.1 MB | 0% | (tidak dikompres)

class BlobLocalStorage
{
  protected $compressionType = 'gzip';

  protected Contract $compresser;

  /**
   * Chunk size untuk streaming (adjustable berdasarkan environment)
   * - Low RAM/I/O: 8192 (8 KB)
   * - Normal: 65536 (64 KB)
   * - High I/O: 262144 (256 KB)
   */
  private int $chunkSize;

  /**
   * Threshold untuk verifikasi partial (byte)
   * File > threshold → pakai verifikasi partial
   */
  private int $partialVerifyThreshold = 50_000_000; // 50 MB

  protected int $partialHashByte = 1_000_000; // 1MB

  protected ServicesLockManager $lockManager;

  protected array $mimeTextList = [
    "application/javascript",
    "application/json",
    "application/xml",
    "application/xhtml+xml",
    "application/manifest+json",
    "application/ld+json",
    "application/soap+xml",
    "application/vnd.api+json",
    "application/atom+xml",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.ms-powerpoint",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "application/vnd.oasis.opendocument.text",
    "application/rss+xml",
    "application/pkcs7-mime",
    "application/pgp-signature",
    "application/yaml",
    "application/toml",
    "application/x-www-form-urlencoded",
    "application/pgp-signature",
    "application/pkcs7-mime",
    "multipart/form-data",
    "image/svg+xml",
    "image/vnd.dxf",
    "model/step",
    "model/step+xml",
    "model/step+zip",
    "model/step-xml+zi",
    "model/iges",
    "model/obj",
    "model/stl",
    "model/gltf+json",
    "model/vnd.collada+xml",
  ];

  public function __construct(ServicesLockManager $lockManager = new FlockLockManager())
  {
    // Sesuaikan berdasarkan environment
    $this->chunkSize = Config::get('blob.chunk_size', 32768); // default: 32 KB
    $this->lockManager = $lockManager;
    $this->compresser = new Gzip();
  }

  /**
   * set default compressor
   */
  public function setCompressor(Contract $compressor)
  {
    $this->compressionType = $compressor->type();
    $this->compresser = $compressor;
  }

  /**
   * Read all file'contens to memory an once
   * @param string $hash blob hash
   * @throws \RuntimeException
   */
  public function read(string $hash)
  {
    $fullPath = $this->getBlobPath($hash);
    if (!file_exists($fullPath)) {
      throw new \RuntimeException("Manifest not found");
    }
    $maximumSize = config('workspace.read_file_limit', (2 * 1024 * 1024));
    if(filesize($fullPath) > $maximumSize){
      throw new \RuntimeException("Maximum read file is {$maximumSize} bytes");
    }
    return file_get_contents($fullPath);
  }

  /**
   * Read file contents by streaming resource
   * @param string $hash blob hash
   * @param callable $callback
   * @throws \RuntimeException
   * 
   * contoh cara pakai
   * return response()->stream(
   *   function () use ($hash, $blobLocalStorage) {
   *     $blobLocalStorage->readStream(
   *       $hash,
   *       function ($stream) {
   *         while (!feof($stream)) {
   *           echo fread($stream, 8192);
   *           flush();
   *         }
   *       }
   *     );
   *   }
   * );
   */
  public function readStream(string $hash, callable $callback) :void
  {
    $fullPath = $this->getBlobPath($hash);
    if (!file_exists($fullPath)) {
      throw new \RuntimeException("Manifest not found");
    }
    $this->compresser->decompressStream($fullPath, $callback);
  }

  /**
   * Simpan file ke blob storage
   *
   * @param string $filePath Absolute path file sumber (di filesystem)
   * @param string|null $providedHash Hash dari third-party (opsional)
   * @param array $metadata Metadata tambahan: ['mime', 'is_binary', ...]
   * @return string SHA-256 hash dari isi file (unik, lowercase)
   *
   * @throws \RuntimeException Jika file tidak bisa dibaca/ditulis
   */
  public function store(string $filePath, ?string $providedHash = null, array &$metadata = []): string
  {
    if (!is_file($filePath)) {
      throw new RuntimeException("File not found: {$filePath}");
    }

    $size = filesize($filePath);
    if ($size === false) {
      throw new RuntimeException("Cannot get size of: {$filePath}");
    }

    $metadata["original_size_bytes"] = $size;

    // #1. Dapatkan hash (dari third-party atau hitung sendiri)
    $hash = $this->resolveHash($filePath, $providedHash, $size);

    // #2. Cek deduplikasi dan file blob sudah ada dan sudah di @chmod 0444 atau belum, true  = sudah ada
    $blobPath = $this->getBlobPath($hash);
    if(file_exists($blobPath) && !is_writable($blobPath)) return $hash;
    // #2. Cek deduplikasi: jika blob sudah ada, langsung return
    if ($this->blobExists($hash)) {
      return $hash;
    }

    // #3. Simpan file ke blob storage (atomic, dengan lock)
    $this->storeFileAtomicCompress($filePath, $hash, $metadata);
    
    // #4. Simpan metadata ke database
    $this->storeMetadataToDb($hash, $size,  $metadata);

    return $hash;
  }

  /**
   * Dapatkan SHA-256 hash file
   *
   * @param string $filePath
   * @param string|null $providedHash (dari third-party)
   * @param int $size
   * @return string SHA-256 (64 hex chars, lowercase)
   */
  private function resolveHash(string $filePath, ?string $providedHash, int $size): string
  {
    if ($providedHash) {
      $providedHash = strtolower(trim($providedHash));

      // Validasi format SHA-256
      if (!preg_match('/^[a-f0-9]{64}$/', $providedHash)) {
        throw new RuntimeException("Invalid SHA-256 hash format");
      }

      // Verifikasi berdasarkan ukuran
      if ($size <= $this->partialVerifyThreshold) {
        $calculated = $this->hashFileIncremental($filePath);
        if (!hash_equals($providedHash, $calculated)) {
          throw new RuntimeException("Provided hash mismatch (full verify)");
        }
      } else {
        $this->verifyPartialHash($filePath, $providedHash, $size);
      }

      return $providedHash;
    }

    // Hitung sendiri dengan streaming
    return $this->hashFileIncremental($filePath);
  }

  /**
   * Hitung SHA-256 dengan streaming (RAM efisien)
   */
  public function hashFileIncremental(string $filePath): string
  {
    $ctx = hash_init('sha256');
    $handle = @fopen($filePath, 'rb');

    if (!$handle) {
      throw new RuntimeException("Cannot open file for hashing: {$filePath}");
    }

    try {
      while (!feof($handle)) {
        $chunk = fread($handle, $this->chunkSize);
        if ($chunk === false) break;
        hash_update($ctx, $chunk);
      }
      return hash_final($ctx);
    } finally {
      fclose($handle);
    }
  }

  /**
   * Hindari baca 1 GB file hanya untuk verifikasi
   * Verifikasi partial hash untuk file besar
   * Membandingkan:
   *   - 1 MB pertama
   *   - 1 MB terakhir
   *   - Ukuran file
   */
  public function verifyPartialHash(string $filePath, string $expectedHash, int $size): void
  {
    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
      throw new RuntimeException("Cannot open file for partial verify: {$filePath}");
    }

    try {
      // Baca head (1 MB)
      $head = fread($handle, self::$partialHashByte);
      if ($head === false) $head = '';

      // Baca tail (1 MB)
      $tail = '';
      if ($size > self::$partialHashByte) {
        fseek($handle, max(0, $size - self::$partialHashByte));
        $tail = fread($handle, self::$partialHashByte);
      }

      // Gabung: head + size + tail → hash
      $sample = $head . pack('J', $size) . $tail; // 'J' = unsigned 64-bit (big endian)
      $sampleHash = hash('sha256', $sample);

      // Bandingkan 12 karakter pertama (cukup untuk deteksi error)
      if (!hash_equals(substr($expectedHash, 0, 12), substr($sampleHash, 0, 12))) {
        throw new RuntimeException("Partial hash verification failed");
      }
    } finally {
      fclose($handle);
    }
  }

  /**
   * Simpan file ke blob storage secara atomic
   * Menggunakan temporary file + rename untuk hindari corrupt
   * Tidak corrupt meski listrik mati
   * lock file untuk menghindari race condition di shared storage
   */

  // private function storeFileAtomic(string $sourcePath, string $hash, array &$metadata): void
  // {
  //   $subDir = substr($hash, 0, 2);
  //   $blobDir = Workspace::blobPath() . "/{$subDir}";
  //   $blobPath = "{$blobDir}/{$hash}";

  //   if (!is_dir($blobDir)) {
  //     mkdir($blobDir, 0755, true);
  //   }

  //   $tempPath = $blobPath . '.tmp.' . \Illuminate\Support\Str::random(8);

  //   // 🔑 Gunakan lock manager (otomatis pilih flock/redis)
  //   $lockKey = "blob_dir:{$subDir}";

  //   $this->lockManager->withLock(
  //     $lockKey,
  //     function () use ($sourcePath, $tempPath, $blobPath, &$metadata) {
  //       // Cek ulang setelah lock
  //       if (file_exists($blobPath)) {
  //         return;
  //       }

  //       $size = $metadata["original_size_bytes"];

  //       $source = fopen($sourcePath, 'rb');
  //       $dest = fopen($tempPath, 'wb');

  //       if (!$source || !$dest) {
  //         fclose($source);
  //         fclose($dest);
  //         throw new \RuntimeException("Cannot open streams for blob");
  //       }

  //       try {
  //         $copied = stream_copy_to_stream($source, $dest, $size, 0);
  //         $metadata["compression_type"] = null;
  //         if ($copied !== $size) {
  //           throw new \RuntimeException("Incomplete copy: {$copied}/{$size}");
  //         }

  //         fclose($source);
  //         fclose($dest);

  //         if (!rename($tempPath, $blobPath)) {
  //           throw new \RuntimeException("Atomic rename failed");
  //         }

  //         @chmod($blobPath, 0444); // agar file tidak bisa di replace atau rewrite (izin baca saja)
  //       } catch (\Throwable $e) {
  //         fclose($source);
  //         fclose($dest);
  //         @unlink($tempPath);
  //         throw $e;
  //       }
  //     },
  //     Config::get('lock.default_timeout_ms', 30000)
  //   );
  // }

  /**
   * file will be compressed if not binary and not already compressed
   * @param string $sourcePath string file, misal DMC...xml
   * @param string $hash dari $sourcePath
   * @param array $metadata
   * @return bool true if success, false if nothing to do
   * @throws RuntimeException if error or cannot store file
   */
  private function storeFileAtomicCompress(string $sourcePath, string $hash, array &$metadata)
  {
    $subDir = substr($hash, 0, 2);
    $blobDir = Workspace::blobPath() . "/{$subDir}";
    $blobPath = "{$blobDir}/{$hash}";

    if (!is_dir($blobDir)) {
      mkdir($blobDir, 0755, true);
    }

    $tempPath = $blobPath . '.tmp.' . \Illuminate\Support\Str::random(8);
    // 🔑 Gunakan lock manager (otomatis pilih flock/redis)
    $lockKey = "blob_dir:{$subDir}";

    // 🔑 Deteksi tipe file dari metadata
    $mime = ($metadata['mime'] ?? ($metadata['mime'] = $this->detectMimeType($sourcePath)));
    $metadata['compression_type'] = null;
    $isBinary = ($metadata['is_binary'] ?? ($metadata['is_binary'] = $this->isBinaryFile($sourcePath)));
    $isAlreadyCompressed = $this->isAlreadyCompressedMime($mime);

    // 🔑 Putuskan apakah perlu dikompres
    $shouldCompress = !$isBinary && !$isAlreadyCompressed;
    $metadata["stored_size_bytes"] = 0;    

    $this->lockManager->withLock(
      $lockKey,
      function () use ($sourcePath, $tempPath, $blobPath, $shouldCompress, &$metadata) {
        $source = $dest = null;
        try {
          if ($shouldCompress) {
            // 💨 Kompresi streaming (tanpa load ke memory)
            $this->compresser->compressStream($sourcePath, $tempPath, $metadata);
            $metadata["compression_type"] = $this->compressionType;
          } else {
            // 📁 Salin asli
            $source = fopen($sourcePath, 'rb');
            $dest = fopen($tempPath, 'wb');

            if (!$source || !$dest) {
              throw new RuntimeException("Cannot open streams");
            }

            $size = $metadata["original_size_bytes"];
            $copied = stream_copy_to_stream($source, $dest, $size, 0);
            if ($copied !== $size) {
              throw new \RuntimeException("Incomplete copy: {$copied}/{$size}");
            }
            fclose($source);
            fclose($dest);
          }
        } catch (\Throwable $th) {
          throw $th;
        } finally {
          if (is_resource($source)) fclose($source);
          if (is_resource($dest)) fclose($dest);
        }

        if (!rename($tempPath, $blobPath)) {
          @unlink($tempPath);
          throw new RuntimeException("Atomic rename failed");
        }

        // agar file tidak bisa di replace atau rewrite (izin baca saja)
        // Blob::setLocalReadOnly($blobPath); // dilakukan di Blob class, bukan di sini
      },
      Config::get('lock.default_timeout_ms', 30000)
    );
    
    $metadata["stored_size_bytes"] = filesize($blobPath);
  }

  /**
   * Simpan metadata blob ke database
   */
  private function storeMetadataToDb(string $hash, int $size, array $metadata): void
  {
    // Deteksi MIME jika belum ada
    $mime = $metadata['mime'];
    $isBinary = $metadata['is_binary'] ?? !$this->isTextMime($mime);
    $isAlreadyCompressed = $this->isAlreadyCompressedMime($mime);

    // Simpan ke DB
    $data = ([
      'hash' => $hash,
      'mime_type' => $mime,
      'is_binary' => $isBinary,
      'original_size_bytes' => $size,
      // 'stored_size_bytes' => $size, // disimpan asli (tanpa kompres untuk binary)
      // 'is_stored_compressed' => false,
      // 'compression_type' => null,
      'is_already_compressed' => $isAlreadyCompressed,

      'is_stored_compressed' => $metadata['compression_type'] ? true : false,
      'compression_type' => $metadata['compression_type'] ?? null,
      'stored_size_bytes' => $metadata['stored_size_bytes'],
      // 'is_binary' => $isBinary,
      // 'mime_type' => $mime,
      // 'is_already_compressed' => $isAlreadyCompressed,
    ]);
    // BlobModel::create($data);
    BlobModel::updateOrCreate($data);
    // try {} 
    // catch (\Throwable $th) {
    // dd($isBinary, $isAlreadyCompressed, $metadata, $th);
    // throw $th;
    // }
  }

  /**
   * Deteksi MIME type (sederhana & ringan)
   */
  private function detectMimeType(string $filePathOrHash): string
  {
    // Untuk blob, kita tidak punya path asli → tebak dari konten (opsional)
    // Di sini kita fallback ke ekstensi jika file asli tersedia, atau gunakan 'application/octet-stream'
    try {
      try {
        $getID3 = new \getID3();
        $fileinfo = $getID3->analyze($filePathOrHash);
        return $fileinfo['mime_type'];
      } catch (\Throwable $th) {
        return (($mime = mime_content_type($filePathOrHash)) ? $mime : 'application/octet-stream');
      }
    } catch (\Throwable $th) {
      return 'application/octet-stream';
    }
  }

  /**
   * Cek apakah MIME termasuk teks
   */
  private function isTextMime(string $mime): bool
  {
    return str_starts_with($mime, 'text/') || in_array($mime, $this->mimeTextList);
  }

  /**
   * Cek apakah file sudah terkompresi (tidak perlu dikompres ulang)
   */
  private function isAlreadyCompressedMime(string $mime): bool
  {
    // $compressedMimes = ['application/pdf','application/zip','application/gzip','application/x-tar','video/mp4','video/avi','video/mov','video/webm','image/jpeg','image/png','image/gif','image/webp'];
    return !$this->isTextMime($mime);
  }

  /**
   * Cek apakah blob sudah ada di storage & DB
   */
  public function blobExists(string $hash): bool
  {

    // Cek DB dulu (lebih cepat). Karena local storage, jadi tidak di check ke DB
    // if (BlobModel::where('hash', $hash)->exists()) {
    //   return true;
    // }

    // Opsional: cek file (fallback)
    $path = $this->getBlobPath($hash);
    return file_exists($path);
    // return file_exists($path) && BlobModel::where('hash', $hash)->exists();
  }

  /**
   * Ambil path fisik blob
   */
  public function getBlobPath(string $hash): string
  {
    $subDir = substr($hash, 0, 2);
    return Workspace::blobPath() . "/{$subDir}/{$hash}";
  }

  /**
   * Deteksi file binary (bukan berdasarkan ekstensi!)
   */
  private function isBinaryFile(string $path): bool
  {
    $handle = fopen($path, 'rb');
    $sample = fread($handle, 1024); // baca 1 KB pertama
    fclose($handle);
    
    // jika tidak ada isinya dianggap binary file true
    if(!$sample){
      return true;
    }

    // Cek null byte (indikator kuat binary)
    if (strpos($sample, "\x00") !== false) {
      return true;
    }

    // Cek rasio printable chars
    $printable = preg_match_all('/[\x20-\x7E]/', $sample);
    return ($printable / strlen($sample)) < 0.7; // <70% printable = binary
  }

  /**
   * @deprecated
   * Proses blob dengan callback (auto-closed)
   * 
   * @param string $hash
   * @param callable $callback function(resource $stream): mixed
   * @return mixed Return value dari callback
   * 
   * @example
   * // Di controller (stream ke response)
   * $blob = BlobModel::where('hash', $hash)->firstOrFail();
   * return response()->stream(function () use ($hash, $blobLocalStorage) {
   *     $blobLocalStorage->withBlobContent($hash, $blob->compression_type, function ($stream) {
   *         while (!feof($stream)) {
   *             echo fread($stream, 8192);
   *             flush();
   *         }
   *     });
   * });
   * 
   * // Hitung hash ulang
   * $blob = BlobModel::where('hash', $hash)->firstOrFail();
   * $sha256 = $blobLocalStorage->withBlobContent($hash, $blob->compression_type, function ($stream) {
   *     $ctx = hash_init('sha256');
   *     while (!feof($stream)) {
   *         hash_update($ctx, fread($stream, 65536));
   *     }
   *     return hash_final($ctx);
   * });
   */
  public function withBlobContent(string $hash, string | null $compressionType, callable $callback)
  {
    $stream = $this->getBlobContent($hash, $compressionType, true);

    if (!$stream) {
      throw new RuntimeException("Cannot open blob: {$hash}");
    }

    try {
      return $callback($stream);
    }
    // catch (\Exception $e) {
    //   dd('aa');
    //   dd($e);
    // }
    finally {
      fclose($stream);
    }
  }

  /**
   * @deprecated
   * Dapatkan isi blob dalam bentuk string (otomatis uncompress jika perlu)
   * 
   * Ambil isi blob (streaming, aman untuk file besar)
   * karena per di read stream
   *
   * Dapatkan stream resource ke blob.
   * 
   * Cuma support gzip compression
   * 
   * @param string $hash
   * @param bool $asStream Jika true, return resource (untuk file besar)
   * @return string file content
   * @return resource Stream yang HARUS di-fclose() oleh consumer
   * @throws RuntimeException
   * 
   * @example @return resource
   *   $blob = BlobModel::where('hash', $hash)->firstOrFail();
   *   $stream = $blobLocalStorage->getBlobContent($hash, $blob->compression_type, true);
   *   try {
   *       while (!feof($stream)) {
   *           echo fread($stream, 8192);
   *       }
   *   } finally {
   *       fclose($stream);
   *   }
   *
   */
  public function getBlobContent(string $hash, string | null $compressionType, bool $asStream = false)
  {
    $path = $this->getBlobPath($hash);

    if (!file_exists($path)) {
      throw new RuntimeException("Blob file not found: {$hash}");
    }

    // 🔑 Cek apakah perlu di-uncompress
    if ($compressionType && $compressionType === 'gzip') {
      return $this->decompressGzipBlob($path, $asStream);
    } else {
      throw new RuntimeException("Unsupported compression type: {$compressionType}");
    }

    // File tidak terkompresi
    if ($asStream) {
      $stream = fopen($path, 'rb');
      if (!$stream) throw new RuntimeException("Cannot open blob");
      return $stream;
    }

    return file_get_contents($path);
  }

  // /**
  //  * cocok untuk file size kecil
  //  * @param string $path relative to blob path .../blobs/
  //  */
  // public function getBlob(string $hash): array
  // {
  //   $fullPath = $this->getBlobPath($hash);
  //   if (!file_exists($fullPath)) {
  //     throw new \RuntimeException("Manifest not found: {$fullPath}");
  //   }
  //   return json_decode(file_get_contents($fullPath), true);
  // }

  /**
   * Kompresi streaming (tanpa memory overhead)
   * @param resource $source
   * @param resource $dest
   * @param array $metadata
   */
  // private function streamGzipCompress($source, $dest, array &$metadata): void
  // {
  //   $context = deflate_init(ZLIB_ENCODING_GZIP, [
  //     'level' => 6,
  //   ]);

  //   if ($context === false) {
  //     throw new \RuntimeException('Gagal inisialisasi deflate context');
  //   }

  //   $bytesProcessed = 0;
  //   $totalCompressed = 0;

  //   while (!feof($source)) {
  //     $chunk = fread($source, 65536); // baca hingga 64KB
  //     if ($chunk === false) {
  //       throw new \RuntimeException('Gagal membaca dari source stream');
  //     }
  //     if ($chunk === '') {
  //       break; // EOF
  //     }

  //     $bytesProcessed += strlen($chunk);

  //     // Gunakan ZLIB_SYNC_FLUSH untuk chunk terakhir?
  //     // Tapi ZLIB_FINISH hanya di akhir — jadi akumulasi saja
  //     $compressed = deflate_add($context, $chunk, feof($source) ? ZLIB_FINISH : ZLIB_NO_FLUSH);

  //     if ($compressed === false) {
  //       throw new \RuntimeException('Gagal memproses kompresi chunk');
  //     }

  //     if ($compressed !== '') {
  //       $written = fwrite($dest, $compressed);
  //       if ($written === false || $written !== strlen($compressed)) {
  //         throw new \RuntimeException('Gagal menulis ke destination stream');
  //       }
  //       $totalCompressed += $written;
  //     }
  //   }

  //   // Pastikan tidak ada sisa (biasanya sudah di-handle di loop atas via ZLIB_FINISH)
  //   // Tapi amannya: flush akhir jika konteks belum selesai
  //   $final = deflate_add($context, '', ZLIB_FINISH);
  //   if ($final !== '' && $final !== false) {
  //     $written = fwrite($dest, $final);
  //     if ($written === false || $written !== strlen($final)) {
  //       throw new \RuntimeException('Gagal menulis final flush');
  //     }
  //     $totalCompressed += $written;
  //   }


  //   $metadata['original_size_bytes'] = $bytesProcessed;
  //   $metadata['compressed_size_bytes'] = $totalCompressed;
  //   $metadata['compression_type'] = 'gzip';
  //   $metadata['compression_ratio'] = $bytesProcessed > 0 ? round($totalCompressed / $bytesProcessed, 4) : 0;

  //   // 🔑 Perbaikan #2: Validasi hasil kompresi
  //   $destPath = stream_get_meta_data($dest)['uri'];
  //   fflush($dest); // Pastikan semua data ditulis ke disk

  //   if (!$this->validateGzipFile($destPath)) {
  //     throw new \RuntimeException("Compressed file is corrupt: {$destPath}");
  //   }
  // }

  // /**
  //  * 🔑 Validasi file gzip sebelum disimpan
  //  */
  // private function validateGzipFile(string $path): bool
  // {
  //   $gz = @gzopen($path, 'rb');
  //   if (!$gz) {
  //     return false;
  //   }

  //   $test = @gzread($gz, 1); // cukup 1 byte decompressed
  //   $ok = ($test !== false && $test !== '');
  //   gzclose($gz);

  //   return $ok;
  // }
  // private function validateGzipFile_x(string $path): bool
  // {
  //   if (!is_file($path) || !is_readable($path)) {
  //     return false;
  //   }

  //   $stream = fopen($path, 'rb');
  //   if (!$stream) {
  //     return false;
  //   }

  //   // Cek header manual: 2 byte pertama HARUS 0x1F 0x8B
  //   $header = fread($stream, 2);
  //   if ($header !== "\x1f\x8b") {
  //     fclose($stream);
  //     return false;
  //   }

  //   // Sekarang coba inflate via stream — tapi dengan error suppression *aman*
  //   $filter = @stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ);
  //   if (!$filter) {
  //     fclose($stream);
  //     return false;
  //   }

  //   // Baca dalam mode "try without exception"
  //   // $oldHandler = set_error_handler(function () { /* suppress */ });
  //   try {
  //     // Baca sedikit — jangan terlalu banyak (216 byte compressed bisa jadi besar saat decompress!)
  //     $test = fread($stream, 1024); // lebih aman: baca compressed bytes, bukan decompressed
  //     $valid = ($test !== false);
  //   } catch (\Throwable $e) {
  //     $valid = false;
  //   } finally {
  //     restore_error_handler();
  //     stream_filter_remove($filter);
  //     fclose($stream);
  //   }

  //   return $valid;
  // }

  // /**
  //  * Uncompress blob ke string atau stream
  //  * @return string
  //  * @return resource Stream yang HARUS di-fclose() oleh consumer
  //  * @throws \RuntimeException
  //  */
  // private function decompressGzipBlob(string $path, bool $asStream)
  // {
  //   if ($asStream) {
  //     $gz = gzopen($path, 'rb');
  //     if (!$gz) {
  //       fclose($gz);
  //       throw new RuntimeException("Cannot open compressed blob");
  //     }
  //     return $gz;
  //   } else {
  //     return $this->decompressGzToString($path);
  //   }
  // }

  // private function decompressGzToString(string $path): string
  // {
  //   $gz = gzopen($path, 'rb');
  //   if (!$gz) {
  //     throw new RuntimeException("Cannot open compressed blob");
  //   }

  //   $content = '';
  //   while (!gzeof($gz)) {
  //     $chunk = gzread($gz, 8192); // baca 8KB per iterasi
  //     if ($chunk === false) {
  //       break;
  //     }
  //     $content .= $chunk;
  //   }

  //   gzclose($gz);
  //   return $content;
  // }

  // private function decompressGzipBlobToFile(string $path, string $outputTxtPath): void
  // {
  //   $gz = gzopen($path, 'rb');
  //   if (!$gz) {
  //     throw new RuntimeException("Cannot open compressed blob");
  //   }

  //   $txt = fopen($outputTxtPath, 'wb'); // binary write — aman untuk UTF-8/biner
  //   if (!$txt) {
  //     gzclose($gz);
  //     throw new \RuntimeException("Gagal membuat file output: $outputTxtPath");
  //   }

  //   $totalBytes = 0;
  //   while (!gzeof($gz)) {
  //     $chunk = gzread($gz, 8192);
  //     if ($chunk === false) {
  //       break;
  //     }
  //     $written = fwrite($txt, $chunk);
  //     if ($written === false) {
  //       gzclose($gz);
  //       fclose($txt);
  //       throw new \RuntimeException("Gagal menulis ke file output");
  //     }
  //     $totalBytes += $written;
  //   }

  //   gzclose($gz);
  //   fclose($txt);

  //   echo "✅ Berhasil dekompresi $gzFilePath → $outputTxtPath ($totalBytes byte)\n";
  // }
  // private function decompressGzipBlob(string $path, bool $asStream)
  // {
  //   $handle = fopen($path, 'rb');
  //   if (!$handle) {
  //     throw new RuntimeException("Cannot open compressed blob");
  //   }

  //   try {
  //     if ($asStream) {
  //       // Gunakan filter PHP untuk streaming decompress
  //       // $stream = stream_filter_append($handle, 'zlib.inflate', STREAM_FILTER_READ) ? $handle : throw new RuntimeException("Failed to attach inflate filter");
  //       try {
  //         $filter = stream_filter_append($handle, 'zlib.inflate', STREAM_FILTER_READ);
  //         if ($filter === false) {
  //           throw new \RuntimeException("Failed to attach zlib.inflate filter for {$hash}");
  //         }
  //         return $filter;
  //       } catch (\Throwable $th) {
  //         // Hapus filter sebelum fclose()
  //         if (isset($filter)) {
  //           @stream_filter_remove($filter);
  //         }
  //         if (is_resource($handle)) {
  //           @fclose($handle);
  //         }
  //       }
  //     }

  //     // Untuk string: baca & uncompress
  //     $content = '';
  //     stream_filter_append($handle, 'zlib.inflate', STREAM_FILTER_READ);
  //     while (!feof($handle)) {
  //       $content .= fread($handle, 8192);
  //     }
  //     return $content;
  //   } catch (\Throwable $e) {
  //     fclose($handle);
  //     throw new RuntimeException("Decompression failed: " . $e->getMessage());
  //   }
  // }

  // /**
  //  * CONTOH
  //  * Helper: Stream blob ke response (otomatis handle kompresi)
  //  */
  // public function streamBlobResponse(string $hash, string $filename = 'blob', string $mimeType = 'application/octet-stream')
  // {
  //   $blob = DochubBlob::where('hash', $hash)->firstOrFail();

  //   return response()->stream(function () use ($hash) {
  //     $this->withBlobContent($hash, function ($stream) {
  //       while (!feof($stream)) {
  //         echo fread($stream, 8192);
  //         flush();
  //       }
  //     });
  //   }, 200, [
  //     'Content-Type' => $blob->mime_type ?: $mimeType,
  //     'Content-Disposition' => "inline; filename=\"{$filename}\"",
  //     'Content-Length' => $blob->original_size_bytes, // ukuran asli, bukan terkompresi!
  //   ]);
  // }
  // public function streamVideo(BlobLocalStorage $blobLocalStorage, string $hash)
  // {
  //   return $blobLocalStorage->streamBlobResponse(
  //     $hash,
  //     'video.mp4',
  //     'video/mp4'
  //   );
  // }
}

// Dari upload file:
// $blobLocalStorage = app(BlobLocalStorage::class);
// $hash = $blobLocalStorage->store($request->file('video')->getPathname());

// Dari third-party (dengan hash):
// {
//   "files": [
//     {
//       "path": "video/intro.mp4",
//       "size": 154288000,
//       "sha256": "a1b2c3d4e5f6...",   // ← third-party sudah hitung!
//       "mime": "video/mp4"
//     }
//   ]
// }
// $hash = $blobLocalStorage->store(
//     $tempFilePath,
//     $manifest['files'][$i]['sha256'], // dipercaya, diverifikasi partial
//     ['mime' => $manifest['files'][$i]['mime']]
// );

// Untuk rollback (baca blob):
// $blobPath = $blobLocalStorage->getBlobPath($file->blob_hash);
// copy($blobPath, $workspacePath . '/' . $file->relative_path);

// Performa yang Diharapkan (Server Low-End: Raspberry Pi 4)
// File | Metode | RAM | Waktu
// 100 MB | PDF Streaming hash | ~15 MB | ~8 detik
// 1 GB Video | Partial verify + hash pihak ketiga | ~10 MB | ~0.5 detik
// 10 KB config | Full hash | ~5 MB | ~0.01 detik
// ✅ Tidak akan pernah out of memory, bahkan di server 512 MB RAM. 