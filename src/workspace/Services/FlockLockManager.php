<?php

namespace Dochub\Workspace\Services;

use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * selanjutnya perbaiki agar setiap $key di sanitasi agar tidak mengandung karakter illegal '/, .., dll', contoh $key = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);
 */
class FlockLockManager implements LockManager
{
  /**
   * Menyimpan handle file lock per kunci
   * @var array<string, resource>
   */
  private array $locks = [];

  public function acquire(string $key, int $timeoutMs = 30000): bool
  {
    $lockFile = Workspace::lockPath() . "/{$key}.lock";
    @mkdir(dirname($lockFile), 0755, true);

    $handle = @fopen($lockFile, 'w');
    if (!$handle) {
      return false;
    }

    $start = microtime(true);
    $timeoutSec = $timeoutMs / 1000.0;

    // Coba lock dengan non-blocking check + sleep
    while (true) {
      if (flock($handle, LOCK_EX | LOCK_NB)) {
        $this->locks[$key] = $handle;
        return true;
      }

      // Cek timeout
      if ((microtime(true) - $start) >= $timeoutSec) {
        fclose($handle);
        return false;
      }

      // Tidur sebentar (1-5 ms)
      usleep(rand(1000, 5000));
    }
  }

  public function release(string $key): void
  {
    if (isset($this->locks[$key])) {
      $handle = $this->locks[$key];
      flock($handle, LOCK_UN);
      fclose($handle);
      unset($this->locks[$key]);

      // Opsional: hapus file lock jika tidak dipakai lagi
      // $lockFile = storage_path("app/locks/{$key}.lock");
      $lockFile = Workspace::lockPath() . "/{$key}.lock";
      @unlink($lockFile);
      @rmdir(dirname($lockFile));
    }
  }

  public function withLock(string $key, callable $callback, int $timeoutMs = 30000)
  {
    if (!$this->acquire($key, $timeoutMs)) {
      throw new RuntimeException("Timeout menunggu lock: {$key}");
    }

    try {
      return $callback();
    } finally {
      $this->release($key);
    }
  }

  public function isLocked(string $key): bool
  {
    $lockFile = Workspace::lockPath() . "/{$key}.lock";
    if (!file_exists($lockFile)) return false;

    $handle = @fopen($lockFile, 'r');
    if (!$handle) return true; // assume locked

    $locked = !flock($handle, LOCK_EX | LOCK_NB);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $locked;
  }
}
