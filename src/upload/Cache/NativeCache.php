<?php

namespace Dochub\Upload\Cache;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache as LaravelCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use TusPhp\Cache\Cacheable;
use Dochub\Upload\Cache\FileCache;
use Dochub\Workspace\Services\LockManager;
use Dochub\Workspace\Workspace;

class NativeCache implements Cacheable, LockContract, CacheCleanup
{
  /** @var CacheRepository */
  protected CacheRepository $store;

  protected int $ttl;
  protected string $_prefix = '';

  protected $_driver = 'file';

  // 🔑 Inject lock manager (opsional: gunakan hanya untuk driver yang butuh)
  protected LockManager $lockManager;

  protected string $_userId = '';

  public function __construct()
  {
    // Jika ada store custom, gunakan.
    // Jika tidak ditentukan, gunakan default Laravel Cache store.
    $this->ttl = env('upload.cache.ttl', 3600);
    $this->_driver = env('upload.cache.driver', 'file');
    $this->_prefix = env('upload.cache.prefix', 'upload:');
    $this->store = LaravelCache::driver($this->_driver);
    $this->lockManager = app(LockManager::class);
  }

  public function userId(?string $id = null)
  {
    return $id ? ($this->_userId = $id) : $this->_userId;
  }

  private function getCacheKey(string $key)
  {
    return $this->_prefix . $this->_userId . $key;
  }

  public function driver(?string $d = null)
  {
    return $d ? ($this->_driver = $d) : $this->_driver;
  }

  public function get(string $key, bool $withExpired = false)
  {
    $cacheKey = $this->getCacheKey($key);

    // Laravel cache otomatis drop expired data
    // $key === $uploadId;
    // $fs = new FileStore(new Filesystem(), config('config.file.path'), null);
    // $path = $fs->path($cacheKey);
    // $gt = $fs->get($cacheKey);
    // $gt = $this->store->get($cacheKey);
    // dd($path, $cacheKey, $gt);
    // dd($path, $gt, $cacheKey);
    return $this->store->get($cacheKey);
  }

  public function getArray($key): array
  {
    $data = $this->get($key) ?? [];
    return is_array($data) ? $data : json_decode($data, true);
  }

  public function set(string $key, $value)
  {
    $cacheKey = $this->getCacheKey($key);

    // Simpan dengan TTL
    $this->store->put($cacheKey, $value, $this->ttl);

    return $value;
  }

  public function delete(string $key): bool
  {
    $cacheKey = $this->getCacheKey($key);
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
   * masih belum benar untuk fungsi ini, karena tidak semua support mencari seluruh keys dengan prefix (kecuali database). 
   * Kalaupun bisa misal pakai scan, itu sangat lambat
   */
  public function keys(): array
  {
    return match ($this->_driver) {
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

  /**
   * -------------------------------
   * implement LockContract
   * -------------------------------
   */

  public function isLocked(string $key): bool
  {
    if ($this->_driver === 'file' && $this->lockManager) {
      return method_exists($this->lockManager, 'isLocked')
        ? $this->lockManager->isLocked($key)
        : file_exists(Workspace::lockPath() . "/{$key}.lock");
    }
    return false;
  }

  public function lock(string $key, int $timeoutSecs = 10): bool
  {
    if ($this->_driver === 'file' && $this->lockManager) {
      return $this->lockManager->acquire($key, $timeoutSecs * 1000);
    }
    return true; // noop untuk Redis/dll
  }

  public function release(string $key): bool
  {
    if ($this->_driver === 'file' && $this->lockManager) {
      $this->lockManager->release($key);
      return true;
    }
    return true;
  }

  public function withLock(string $key, callable $callback, int $timeoutSecs = 10): mixed
  {
    if ($this->_driver === 'file' && $this->lockManager) {
      return $this->lockManager->withLock($key, $callback, $timeoutSecs * 1000);
    }

    // Fallback: tanpa lock (misal Redis, atau dev env)
    return $callback();
  }


  /**
   * -------------------------------
   * implement CacheCleanup
   * -------------------------------
   */

  public function cleanupIfCompleted(
    string $uploadId,
    array $metadata,
  ): bool {
    if (count($metadata) < 1) return true; // artinya sudah dibersihkan
    // Cek status selesai
    $isCompleted = ($metadata['status'] ?? '') === 'completed';

    // Cek progress 100% // sudah dilakukan di controller
    // $progress = $metadata['total_chunks'] ? ($metadata['uploaded_chunks'] ?? 0) / $metadata['total_chunks'] : 0;
    // $isFullyUploaded = $progress >= 1.0;

    // Cleanup jika: selesai diproses ATAU upload 100% tapi belum diproses > 5 menit (5 x 60 detik = 300). 86400 = 24*60*60 = sehari
    // $shouldCleanup = $isCompleted || ($isFullyUploaded && ($metadata['updated_at'] ?? 0) < time() - 300);
    $shouldCleanup = $isCompleted || (($metadata['updated_at'] ?? 0) < time() - 300);

    if ($shouldCleanup) {
      return $this->cleanupUpload($uploadId, $metadata);
    }

    return false;
  }

  public function cleanupUpload(
    string $uploadId,
    array $metadata = []
  ): bool {
    if (count($metadata) < 1) return true; // artinya sudah dibersihkan

    $cacheKey = $this->getCacheKey($uploadId);

    try {
      // Hapus dari cache
      $deleted = $this->store->forget($cacheKey);

      // Hapus file fisik jika ada (sama seperti RedisCleanupService)
      if (isset($metadata['process_id'])) {
        $driverUpload = config('upload.driver') === 'tus' ? 'tus' : 'native'; // walau auto adalah file
        $directory = $metadata['upload_dir'] ?? config("upload.driver.{$driverUpload}.root") . "/{$uploadId}";
        if (is_dir($directory)) {
          $this->deleteDirectory($directory);
        }
      }

      // Log::info("Cache upload cleaned up", [
      //   'process_id' => $uploadId,
      //   'driver' => $driver ?? config('cache.default'),
      //   'reason' => $metadata['status'] ?? 'auto',
      //   'chunks' => $metadata['uploaded_chunks'] ?? 0,
      // ]);

      return $deleted;
    } catch (\Exception $e) {
      Log::error("Cache cleanup failed", [
        'process_id' => $uploadId,
        'driver' => $driver ?? config('cache.default'),
        'error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * Hapus direktori upload file rekursif (shared dengan RedisCleanupService)
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
  public function cleanupExpired(int $batchSize = 100): int
  {
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
   * belum di buat untuk memcached/file. Baru support redis
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
