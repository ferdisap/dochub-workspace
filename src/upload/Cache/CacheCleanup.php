<?php

namespace Dochub\Upload\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

interface CacheCleanup
{
  /**
   * Bersihkan batch cache expired
   * 
   * @param int $batchSize
   * @param string $driver
   * @return int Jumlah yang dibersihkan
   */
  public function cleanupExpired(int $batchSize = 100): int;

  /**
   * Bersihkan jika upload selesai
   */
  public function cleanupIfCompleted(string $uploadId, array $metadata): bool;

  /**
   * Bersihkan upload kapanpun
   * juga menghapus directory upload
   */
  public function cleanupUpload(string $uploadId, array $metadata = []): bool;
}
