<?php

namespace Dochub\Upload\Providers;

use Dochub\Upload\EnvironmentDetector;
use Dochub\Upload\Services\TusRedis;
use Dochub\Upload\Tus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use TusPhp\Cache\Cacheable;
use TusPhp\Tus\Server;

/**
 * @deprecated
 */
class TusServiceProvider extends ServiceProvider
{
  public function register()
  {
    // $this->mergeConfigFrom(__DIR__ . '/../../../config/redis.php', 'dochub_redis');

    $this->app->singleton(Server::class, function ($app) {
      $server = new Server();
      $server->setUploadDir(Tus::path());

      // 🔑 Auto-detect Redis backend
      $cache = $this->getRedisCache();
      if ($cache) {
        $server->setCache($cache);
      }

      return $server;
    });
  }

  public function boot()
  {
    // 🔑 DETEKSI DI AWAL BOOTSTRAP
    $envType = EnvironmentDetector::getEnvironment();
    $uploadConfig = EnvironmentDetector::getRecommendedConfig();

    // Konfigurasi dinamis berdasarkan environment
    config([
      'upload.max_size' => $uploadConfig['max_file_size'],
      'upload.chunk_size' => $uploadConfig['chunk_size'],
      'queue.timeout' => $uploadConfig['timeout'],
    ]);

    // Nonaktifkan Tus di shared hosting
    if ($envType === 'shared') {
      config(['tus.enabled' => false]);
    }

    // Log untuk debugging
    // Log::info("Environment detected: {$envType}", [
    //   'config' => $uploadConfig,
    //   'memory_limit' => ini_get('memory_limit'),
    //   'max_execution_time' => ini_get('max_execution_time'),
    // ]);
  }

  /**
   * Dapatkan instance Redis yang kompatibel
   * 
   * @return Cacheable|null
   */
  private function getRedisCache(): ?Cacheable
  {
    // 1. Coba ekstensi PHP Redis (tercepat)
    if (Tus::redis_client() === 'phpredis' && extension_loaded('redis')) {
      try {
        $redis = new \Redis();
        $redis->connect(
          Tus::redis_host(),
          Tus::redis_port(),
          Tus::redis_connection_timeout()
        );

        if (Tus::redis_username() && Tus::redis_password()) {
          $redis->auth(Tus::redis_username(), Tus::redis_password());
        } elseif (Tus::redis_password()) {
          $redis->auth(Tus::redis_password());
        }

        $redis->select(Tus::redis_database());

        // Tes koneksi
        $redis->ping();

        return $this->createTusRedisCache($redis);
      } catch (\Exception $e) {
        Log::warning("Redis extension failed: " . $e->getMessage());
      }
    }
    // 2. Fallback ke Predis (library Composer)
    else {
      try {
        $client = new \Predis\Client([
          'scheme' => 'tcp',
          'host'   => Tus::redis_host(),
          'port'   => Tus::redis_port(),
          'database' => Tus::redis_database(),
          'password' => Tus::redis_password(),
          'timeout' => 5.0,
        ]);

        // Tes koneksi
        $client->ping();

        return $this->createTusRedisCache($client);
      } catch (\Exception $e) {
        Log::warning("Predis failed: " . $e->getMessage());
      }
    }

    // 3. Fallback ke file cache (untuk development)
    Log::info("Using file cache for Tus (Redis not available)");
    return null; // Tus akan pakai file cache default
  }

  /**
   * Buat adapter cache yang kompatibel dengan Tus
   */
  private function createTusRedisCache($redis): Cacheable
  {
    // Jika pakai ekstensi Redis
    return new TusRedis($redis);

    throw new \RuntimeException("Unsupported Redis client");
  }
}
