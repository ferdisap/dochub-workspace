<?php

namespace Dochub\Workspace\Services;

// (Untuk testing / environment khusus)
class NullLockManager implements LockManager
{
  public function acquire(string $key, int $timeoutMs = 30000): bool
  {
    return true; // Selalu sukses
  }

  public function release(string $key): void
  {
    // Tidak melakukan apa-apa
  }

  public function withLock(string $key, callable $callback, int $timeoutMs = 30000)
  {
    return $callback();
  }
}
