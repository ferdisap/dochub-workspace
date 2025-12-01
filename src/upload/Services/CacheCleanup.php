<?php

namespace Dochub\Upload\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class CacheCleanup
{
  protected static $driver;

  /**
   * Pola kunci cache untuk upload
   */
  private const UPLOAD_KEY_PATTERN = 'upload:*';

  public static function driver(string $driver)
  {
    self::$driver = $driver;
  }

  /**
   * Bersihkan cache upload jika sudah selesai
   * 
   * @param string $uploadId
   * @param array $metadata
   * @param string $driver Nama driver cache (default: config('cache.default'))
   * @return bool True jika dibersihkan
   */
  public static function cleanupIfCompleted(
    string $uploadId,
    array $metadata,
    ?string $driver = null
  ): bool {
    self::$driver = config('cache.default');
    // $cache = self::getCacheInstance($driver ?? self::$driver);

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
      ($isFullyUploaded && ($metadata['updated_at'] ?? 0) < time() - 300);

    if ($shouldCleanup) {
      return self::cleanupUpload($uploadId, $metadata, $driver);
    }

    return false;
  }

  /**
   * Bersihkan satu upload dari cache
   * 
   * @param string $uploadId
   * @param array $metadata
   * @param string $driver
   * @return bool
   */
  public static function cleanupUpload(
    string $uploadId,
    array $metadata = [],
    ?string $driver = null
  ): bool {
    $cache = self::getCacheInstance($driver ?? self::$driver);
    $key = "upload:{$uploadId}";

    try {
      // Hapus dari cache
      $deleted = $cache->forget($key);

      // Hapus file fisik jika ada (sama seperti RedisCleanupService)
      if (isset($metadata['upload_id'])) {
        $uploadDir = storage_path("app/native/{$uploadId}");
        if (is_dir($uploadDir)) {
          self::deleteDirectory($uploadDir);
        }
      }

      Log::info("Cache upload cleaned up", [
        'upload_id' => $uploadId,
        'driver' => $driver ?? config('cache.default'),
        'reason' => $metadata['status'] ?? 'auto',
        'chunks' => $metadata['uploaded_chunks'] ?? 0,
      ]);

      return $deleted;
    } catch (\Exception $e) {
      Log::error("Cache cleanup failed", [
        'upload_id' => $uploadId,
        'driver' => $driver ?? config('cache.default'),
        'error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Bersihkan batch cache expired
   * 
   * @param int $batchSize
   * @param string $driver
   * @return int Jumlah yang dibersihkan
   */
  public static function cleanupExpired(
    int $batchSize = 100,
    ?string $driver = null
  ): int {
    // $cache = self::getCacheInstance($driver ?? self::$driver);
    // $cleaned = 0;

    // Untuk driver yang mendukung scanning (Redis, Memcached)
    if (self::supportsScan($driver)) {
      return self::cleanupWithScan($batchSize, $driver);
    }

    // Untuk driver file/database: fallback ke prefix scan
    return self::cleanupWithPrefix($batchSize, $driver);
  }

  /**
   * Dapatkan instance cache dengan driver tertentu
   */
  private static function getCacheInstance(?string $driver = null): \Illuminate\Contracts\Cache\Repository
  {
    if ($driver) {
      return Cache::driver($driver);
    }
    return Cache::store(); // Gunakan default
  }

  /**
   * Cek apakah driver mendukung scanning
   */
  private static function supportsScan(?string $driver = null): bool
  {
    $driver = $driver ?? config('cache.default');
    $supported = ['redis', 'memcached'];
    return in_array($driver, $supported);
  }

  /**
   * Cleanup dengan Redis SCAN (lebih efisien)
   */
  private static function cleanupWithScan(int $batchSize, ?string $driver = null): int
  {
    $cache = self::getCacheInstance($driver ?? self::$driver);
    $cleaned = 0;

    // Hanya untuk Redis
    // if ($cache->getStore() instanceof \Illuminate\Redis) {
    if (Cache::driver($driver) === 'redis') {
      $cursor = 0;
      do {
        // SCAN dengan pattern
        [$cursor, $keys] = \Illuminate\Support\Facades\Redis::scan($cursor, [
          'MATCH' => self::UPLOAD_KEY_PATTERN,
          'COUNT' => $batchSize
        ]);

        foreach ($keys as $key) {
          $metadata = $cache->get($key);
          if ($metadata) {
            $id = str_replace('upload:', '', $key);
            if (self::cleanupIfCompleted($id, $metadata, $driver)) {
              $cleaned++;
            }
          } else {
            // Hapus key kosong
            $cache->forget($key);
            $cleaned++;
          }
        }
      } while ($cursor != 0);
    }

    return $cleaned;
  }

  /**
   * Cleanup dengan prefix (untuk file/database driver)
   */
  private static function cleanupWithPrefix(int $batchSize, ?string $driver = null): int
  {
    $cache = self::getCacheInstance($driver ?? self::$driver);
    $cleaned = 0;

    // Dapatkan semua keys (hati-hati untuk driver file/database!)
    try {
      // Coba dapatkan keys via cache store
      $allKeys = method_exists($cache->getStore(), 'getKeys')
        ? $cache->getStore()->getKeys()
        : self::getAllCacheKeys($driver);

      $uploadKeys = array_filter($allKeys, function ($key) {
        return Str::startsWith($key, 'upload:');
      });

      foreach ($uploadKeys as $key) {
        $metadata = $cache->get($key);
        if ($metadata) {
          $id = str_replace('upload:', '', $key);
          if (self::cleanupIfCompleted($id, $metadata, $driver)) {
            $cleaned++;
          }
        } else {
          $cache->forget($key);
          $cleaned++;
        }
      }
    } catch (\Exception $e) {
      Log::warning("Full cache scan not supported for driver {$driver}", [
        'error' => $e->getMessage()
      ]);
      // Fallback ke cleanup individual
      $cleaned = self::cleanupIndividual($driver);
    }

    return $cleaned;
  }

  /**
   * Dapatkan semua cache keys (fallback untuk driver tertentu)
   */
  private static function getAllCacheKeys(?string $driver = null): array
  {
    $driver = $driver ?? config('cache.default');

    return match ($driver) {
      'file' => self::getFileCacheKeys(),
      'database' => self::getDatabaseCacheKeys(),
      'array' => [], // Tidak perlu cleanup
      default => []
    };
  }

  /**
   * Dapatkan keys dari file cache
   */
  private static function getFileCacheKeys(): array
  {
    $cachePath = storage_path('framework/cache/data');
    $keys = [];

    if (!is_dir($cachePath)) return $keys;

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($cachePath, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        if (preg_match('/\'key\'\s*=>\s*\'([^\']+)\'/', $content, $matches)) {
          $keys[] = $matches[1];
        }
      }
    }

    return $keys;
  }

  /**
   * Dapatkan keys dari database cache
   */
  private static function getDatabaseCacheKeys(): array
  {
    $table = config('cache.stores.database.table', 'cache');
    return DB::table($table)->pluck('key')->toArray();
  }

  /**
   * Cleanup individual (safe untuk semua driver)
   */
  private static function cleanupIndividual(?string $driver = null): int
  {
    // Strategi aman: cleanup berdasarkan TTL alami
    // Cache Laravel secara otomatis menghapus expired keys
    // Jadi kita hanya perlu log monitoring

    Log::info("Cache cleanup skipped - relying on automatic expiration", [
      'driver' => $driver ?? config('cache.default')
    ]);

    return 0;
  }

  /**
   * Hapus direktori rekursif (shared dengan RedisCleanupService)
   */
  public static function deleteDirectory(string $dir): void
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
   * Dapatkan statistik cache upload
   */
  public static function getStats(?string $driver = null): array
  {
    $cache = self::getCacheInstance($driver ?? self::$driver);
    $stats = [
      'total' => 0,
      'uploading' => 0,
      'processing' => 0,
      'completed' => 0,
      'driver' => $driver ?? config('cache.default'),
    ];

    try {
      if (self::supportsScan($driver)) {
        $stats = self::getStatsWithScan($driver);
      } else {
        $stats = self::getStatsWithPrefix($driver);
      }
    } catch (\Exception $e) {
      Log::warning("Cache stats collection failed", [
        'driver' => $driver ?? config('cache.default'),
        'error' => $e->getMessage()
      ]);
    }

    return $stats;
  }

  /**
   * Statistik dengan SCAN
   */
  private static function getStatsWithScan(?string $driver = null): array
  {
    $cache = self::getCacheInstance($driver ?? self::$driver);
    $stats = ['total' => 0, 'uploading' => 0, 'processing' => 0, 'completed' => 0];

    // if ($cache->getStore() instanceof \Illuminate\Redis\Cache\RedisStore) {
    if (Cache::driver($driver) === 'redis') {
      $cursor = 0;

      do {
        [$cursor, $keys] = \Illuminate\Support\Facades\Redis::scan($cursor, [
          'MATCH' => self::UPLOAD_KEY_PATTERN,
          'COUNT' => 1000
        ]);

        foreach ($keys as $key) {
          $metadata = $cache->get($key);
          if ($metadata) {
            $stats['total']++;

            $data = is_string($metadata) ? json_decode($metadata, true) : $metadata;
            if (!$data) continue;

            $progress = $data['total_chunks'] ?
              ($data['uploaded_chunks'] ?? 0) / $data['total_chunks'] : 0;

            if ($progress < 1.0) {
              $stats['uploading']++;
            } elseif (isset($data['job_id'])) {
              $stats['processing']++;
            } else {
              $stats['completed']++;
            }
          }
        }
      } while ($cursor != 0);
    }

    return $stats;
  }

  /**
   * Statistik dengan prefix
   */
  private static function getStatsWithPrefix(?string $driver = null): array
  {
    $cache = self::getCacheInstance($driver ?? self::$driver);
    $stats = ['total' => 0, 'uploading' => 0, 'processing' => 0, 'completed' => 0];

    try {
      $allKeys = self::getAllCacheKeys($driver);
      $uploadKeys = array_filter($allKeys, fn($k) => Str::startsWith($k, 'upload:'));

      foreach ($uploadKeys as $key) {
        $metadata = $cache->get($key);
        if ($metadata) {
          $stats['total']++;

          $data = is_string($metadata) ? json_decode($metadata, true) : $metadata;
          if (!$data) continue;

          $progress = $data['total_chunks'] ?
            ($data['uploaded_chunks'] ?? 0) / $data['total_chunks'] : 0;

          if ($progress < 1.0) {
            $stats['uploading']++;
          } elseif (isset($data['job_id'])) {
            $stats['processing']++;
          } else {
            $stats['completed']++;
          }
        }
      }
    } catch (\Exception $e) {
      // Silent fail
    }

    return $stats;
  }
}
