<?php

namespace Dochub\Upload\Cache;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache as LaravelCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use TusPhp\Cache\Cacheable;
use Dochub\Upload\Cache\FileCache;

class NativeCache implements Cacheable, CacheCleanup
{
  /** @var CacheRepository */
  protected CacheRepository $store;

  protected int $ttl;
  protected string $_prefix = '';

  protected $_driver = 'file';

  public function __construct()
  {
    // Jika ada store custom, gunakan.
    // Jika tidak ditentukan, gunakan default Laravel Cache store.
    $this->store = LaravelCache::store();
    $this->ttl = env('upload.cache.ttl', 3600);

    $this->_driver = env('upload.cache.driver', 'file');
    $this->_prefix = env('upload.cache.prefix', 'upload:');
  }

  public function driver(?string $d = null)
  {
    return $d ? ($this->_driver = $d) : $this->_driver;
  }

  public function get(string $key, bool $withExpired = false)
  {
    $cacheKey = $this->_prefix . $key;

    // Laravel cache otomatis drop expired data
    return $this->store->get($cacheKey);
  }

  public function set(string $key, $value)
  {
    $cacheKey = $this->_prefix . $key;

    // Simpan dengan TTL
    $this->store->put($cacheKey, $value, $this->ttl);

    return $value;
  }

  public function delete(string $key): bool
  {
    $cacheKey = $this->_prefix . $key;
    return $this->store->forget($cacheKey);
  }

  public function deleteAll(array $keys): bool
  {
    $existed = [];
    foreach ($keys as $key) {
      if ($this->store->has($this->_prefix . $key)) {
        $existed[] = $key;
      }
    }
    if (count($existed) < count($keys)) {
      return false;
    } else {
      foreach ($keys as $key) {
        $this->store->forget($this->_prefix . $key);
      }
      return true;
    }
  }

  public function getTtl(): int
  {
    return $this->ttl;
  }

  /** 
   * Tidak semua driver mendukung listing keys.
   * Jadi kita simpan daftar key secara manual.
   * nanti dikembangkan, jika 'database', ambil di db, jika di file maka nanti ambil pakai regex
   */
  public function keys(): array
  {
    $driver = $driver ?? config('cache.default');

    return match ($driver) {
      'file' => FileCache::keys($this->_prefix),
      'database' => DatabaseCache::keys($this->_prefix),
      'redis' => RedisCache::keys($this->_prefix),
      default => []
    };
  }

  public function setPrefix(string $prefix): self
  {
    $this->_prefix = rtrim($prefix, ':') . ':'; // jaga agar format konsisten

    return $this;
  }

  public function getPrefix(): string
  {
    return $this->_prefix;
  }

  public function cleanupIfCompleted(
    string $uploadId,
    array $metadata,
  ): bool {
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
      return $this->cleanupUpload($uploadId, $metadata);
    }

    return false;
  }

  public function cleanupUpload(
    string $uploadId,
    array $metadata = []
  ): bool {

    $key = $this->_prefix . $uploadId;

    try {
      // Hapus dari cache
      $deleted = $this->store->forget($key);

      // Hapus file fisik jika ada (sama seperti RedisCleanupService)
      if (isset($metadata['upload_id'])) {
        $uploadDir = config('upload.driver.native.root') . "/{$uploadId}";
        if (is_dir($uploadDir)) {
          $this->deleteDirectory($uploadDir);
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
   * Hapus direktori rekursif (shared dengan RedisCleanupService)
   */
  private function deleteDirectory(string $dir): void
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

  // /**
  //  * Helper — menyimpan daftar keys (dipanggil di set)
  //  * Tidak semua driver mendukung listing keys.
  //  */
  // protected function rememberKey(string $key): void
  // {
  //   $indexKey = $this->_prefix . '__keys__';

  //   $keys = $this->store->get($indexKey, []);
  //   if (!in_array($key, $keys)) {
  //     $keys[] = $key;
  //     $this->store->put($indexKey, $keys, $this->ttl);
  //   }
  // }

  /**
   * Bersihkan batch cache expired
   * 
   * @param int $batchSize
   * @param string $driver
   * @return int Jumlah yang dibersihkan
   */
  public function cleanupExpired(int $batchSize = 100): int {
    // $cache = self::getCacheInstance($driver ?? self::$driver);
    // $cleaned = 0;

    // Untuk driver yang mendukung scanning (Redis, Memcached)
    if ($this->supportsScan($this->_driver)) {
      return $this->cleanupWithScan($batchSize, $this->_driver);
    }

    // Untuk driver file/database: fallback ke prefix scan
    return $this->cleanupWithPrefix($batchSize, $this->_driver);
  }


  /**
   * Cek apakah driver mendukung scanning
   */
  private function supportsScan(): bool
  {
    $supported = ['redis', 'memcached', 'predis', 'phpredis'];
    return in_array($this->_driver, $supported);
  }

  /**
   * Cleanup dengan SCAN (lebih efisien)
   * belum di buat untuk memcached. Baru support redis
   */
  private function cleanupWithScan(int $batchSize): int
  {
    if ($this->_driver === 'predis' || $this->_driver === 'phpredis') {
      $keys = RedisCache::safeKeysInProduction($this->_prefix, $batchSize);
      return $this->deleteAll($keys) ? count($keys) : 0;
    }
    return 0;
  }

  /**
   * Cleanup dengan prefix (untuk file/database driver)
   */
  private function cleanupWithPrefix(int $batchSize = 0): int
  {
    $keys = $this->keys();
    $c = $batchSize ? $batchSize : count($keys);
    $cleaned = 0;
    for ($i = 0; $i < $c; $i++) {
      $this->delete($keys[$i]);
      $cleaned++;
    }
    return $cleaned;
  }
}
