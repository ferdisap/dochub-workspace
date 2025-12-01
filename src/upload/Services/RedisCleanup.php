<?php

namespace Dochub\Upload\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisCleanup
{
  /**
   * Cleanup upload metadata jika sudah selesai
   * 
   * @param string $uploadId
   * @param array $metadata
   * @return bool True jika dibersihkan
   */
  public static function cleanupIfCompleted(string $uploadId, array $metadata): bool
  {
    // Cek status selesai
    $isCompleted = (
      ($metadata['status'] ?? '') === 'completed' ||
      ($metadata['job_id'] ?? null) !== null // Sudah diproses
    );

    // Cek progress 100%
    $progress = $metadata['total_chunks'] ?
      ($metadata['uploaded_chunks'] ?? 0) / $metadata['total_chunks'] : 0;

    $isFullyUploaded = $progress >= 1.0;

    // Cleanup jika: selesai diproses ATAU upload 100% tapi belum diproses > 5 menit
    $shouldCleanup = $isCompleted ||
      ($isFullyUploaded && $metadata['updated_at'] < time() - 300);

    if ($shouldCleanup) {
      return self::cleanupUpload($uploadId, $metadata);
    }

    return false;
  }

  /**
   * Cleanup satu upload
   */
  public static function cleanupUpload(string $uploadId, array $metadata = []): bool
  {
    try {
      // Hapus metadata
      $deleted = Redis::del("upload:{$uploadId}");

      // Hapus file fisik jika ada
      if (isset($metadata['upload_id'])) {
        $uploadDir = config('upload.driver.native.root') . '/{$uploadId}';
        if (is_dir($uploadDir)) {
          self::deleteDirectory($uploadDir);
        }
      }

      Log::info("Upload cleaned up", [
        'upload_id' => $uploadId,
        'reason' => $metadata['status'] ?? 'auto',
        'chunks' => $metadata['uploaded_chunks'] ?? 0,
      ]);

      return $deleted > 0;
    } catch (\Exception $e) {
      Log::error("Cleanup failed", [
        'upload_id' => $uploadId,
        'error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Hapus direktori rekursif
   */
  private static function deleteDirectory(string $dir): void
  {
    if (!is_dir($dir)) return;

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
  }

  /**
   * Cleanup batch untuk maintenance
   */
  public static function cleanupExpired(int $batchSize = 100): int
  {
    $pattern = 'upload:native_*';
    $cursor = 0;
    $cleaned = 0;

    do {
      [$cursor, $keys] = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', $batchSize);

      foreach ($keys as $key) {
        $metadata = Redis::get($key);
        if ($metadata) {
          $data = json_decode($metadata, true);
          if (self::cleanupIfCompleted(str_replace('upload:', '', $key), $data)) {
            $cleaned++;
          }
        } else {
          // Hapus key kosong
          Redis::del($key);
          $cleaned++;
        }
      }
    } while ($cursor !== 0);

    return $cleaned;
  }
}
