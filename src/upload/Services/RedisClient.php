<?php

namespace Dochub\Upload\Services;

use Illuminate\Support\Facades\App;

class RedisClient
{
  private static ?\Redis $phpredis = null;
  private static ?\Predis\Client $predis = null;

  public static function getInstance(): \Redis|\Predis\Client
  {

    $config = config('upload.redis');
    $clientType = $config['client'];

    // dd(class_exists('\Predis\Client'));
    // dd($config);
    return self::createPredis($config);


    if ($clientType === 'phpredis' && extension_loaded('redis')) {
      return self::$phpredis ??= self::createPhpRedis($config);
    }

    return self::$predis ??= self::createPredis($config);
  }

  private static function createPhpRedis(array $config): \Redis
  {
    $redis = new \Redis();
    $redis->connect(
      $config['host'],
      $config['port'],
      $config['connection_timeout']
    );

    if ($config['read_write_timeout']) {
      $redis->setOption(\Redis::OPT_READ_TIMEOUT, $config['read_write_timeout']);
      $redis->setOption(\Redis::OPT_WRITE_TIMEOUT , $config['read_write_timeout']);
    }

    if ($config['password']) {
      $redis->auth($config['password']);
    }
    $redis->select($config['database']);

    return $redis;
  }

  private static function createPredis(array $config): \Predis\Client
  {
    return new \Predis\Client([
      'scheme' => 'tcp',
      'host' => $config['host'],
      'port' => $config['port'],
      'password' => $config['password'],
      'database' => $config['database'],
      'timeout' => $config['connection_timeout'],
      'read_write_timeout' => $config['read_write_timeout'],
    ]);
  }
}
