<?php

namespace Dochub\Upload\Cache;

interface LockContract
{

  /**
   * Check apakah key di lock atau tidak
   * @param string $key
   */
  public function isLocked(string $key):bool;

  /**
   * @param string $key
   * @param int $timeoutSecs timeout locking
   */
  public function lock(string $key, int $timeoutSecs): bool;

  /**
   * release file locking
   * @param string $key
   */
  public function release(string $key): bool;

  /**
   * lock with callback
   * @param string $key
   * @param callable $callback
   * @param int $timeoutSecs timeout locking
   */
  public function withLock(string $key, callable $callback, int $timeoutSecs = 10):mixed;
}
