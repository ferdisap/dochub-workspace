<?php

namespace Dochub\Upload;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * @deprecated
 */
class Tus {

  public static function path(?string $path = null) : string
  {
    return Config::get("tus.root", App::storagePath('tus')) . ($path ? "/{$path}" : '');
  }

  public static function isUseRedis() :bool
  {
    return Config::get("tus.use_redis", false);
  }

  public static function redis_host():string
  {
    return Config::get('tus.redis.host', '127.0.0.1');
  }

  public static function redis_port():int
  {
    return Config::get('tus.redis.port', 6379);
  }
  
  public static function redis_database():int
  {
    return Config::get('tus.redis.database', 0);
  }
  public static function expiration():int 
  {
    return Config::get('tus.expiration', 604800); // 7 hari
  }

  public static function redis_connection_timeout():int 
  {
    return Config::get('tus.redis.connection_timeout', 5.0); // 5 detik
  }
  public static function redis_username():string 
  {
    return Config::get('tus.redis.username');
  }
  public static function redis_password():string 
  {
    return Config::get('tus.redis.password');
  }
  public static function cache_ttl():int 
  {
    return Config::get('tus.cache_ttl', 86400); // 24 jam
  }
  public static function cache_prefix():string 
  {
    return Config::get('tus.cache_prefix', 'tus:');
  }
  public static function redis_scan_count():int 
  {
    return Config::get('tus.scan_count',100); // 5 detik
  }
  public static function production_safe():bool 
  {
    return Config::get('tus.production_safe', env('APP_ENV') === 'production'); // 5 detik
  }
  public static function max_size():int 
  {
    return Config::get('tus.max_size', 10 * 1024 * 1024 * 1024); // 10 Gb
  }
  public static function redis_client():string 
  {
    return Config::get('tus.redis.client', 'predis');
  }
}