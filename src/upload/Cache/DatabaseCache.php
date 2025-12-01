<?php

namespace Dochub\Upload\Cache;

use Illuminate\Support\Facades\DB;

class DatabaseCache
{
  /**
   * Dapatkan keys dari file cache berdasarkan prefix
   */
  public static function keys(string $prefix): array
  {
    $table = config('cache.stores.database.table', 'cache');
    return DB::table($table)->where('key', 'LIKE', "{$prefix}%")->pluck('key')->toArray();
  }
}
