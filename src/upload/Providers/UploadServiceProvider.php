<?php

namespace Dochub\Upload\Providers;

use Dochub\Upload\EnvironmentDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class UploadServiceProvider extends ServiceProvider
{
  public function register()
  {
    $this->mergeConfigFrom(__DIR__ . '/../../../config/upload.php', 'upload');

    // Auto-detect jika UPLOAD_DRIVER=auto
    if (config('upload.default') === 'auto') {
      $envType = EnvironmentDetector::getEnvironment();
      $strategy = config("upload.strategy.{$envType}", 'native');

      config(['upload.default' => $strategy]);
    }

    // Validasi konfigurasi
    $this->validateConfig();
  }

  public function boot()
  {
    // Pastikan folder upload ada
    $this->ensureDirectories();

    // Jadwalkan cleanup otomatis
    // if (config('upload.cleanup.enabled')) {
    //   $this->app->terminating(function () {
    //     $this->scheduleCleanup();
    //   });
    // }

    // Log untuk debugging
    // Log::info('Upload system initialized', [
    //   'driver' => config('upload.default'),
    //   'max_size' => config('upload.max_size'),
    //   'tus_redis' => config('upload.driver.tus.use_redis'),
    // ]);

    // bisa di tiadakan syntax dibawah ini jika mau
    // 🔑 DETEKSI DI AWAL BOOTSTRAP
    $envType = EnvironmentDetector::getEnvironment();
    $uploadConfig = EnvironmentDetector::getRecommendedConfig();

    // Konfigurasi dinamis berdasarkan environment
    $maxSize = config('upload.max_size') <= $uploadConfig['max_size'] ? config('upload.max_size') : $uploadConfig['max_size'];
    $chunkSize = config('upload.chunk_size') <= $uploadConfig['chunk_size'] ? config('upload.chunk_size') : $uploadConfig['chunk_size'];
    $timeout = config('upload.timeout') <= $uploadConfig['timeout'] ? config('upload.timeout') : $uploadConfig['timeout'];
    config([
      'upload.max_size' => $maxSize,
      'upload.chunk_size' => $chunkSize,
      'queue.timeout' => $timeout,
    ]);

    // Nonaktifkan Tus di shared/serverless hosting
    // config(['upload.driver' => EnvironmentDetector::uploadStrategy()]);

    // Log untuk debugging
    // Log::info("Environment detected: {$envType}", [
    //   'config' => $uploadConfig,
    //   'memory_limit' => ini_get('memory_limit'),
    //   'max_execution_time' => ini_get('max_execution_time'),
    // ]);
  }

  private function validateConfig()
  {
    $driver = config('upload.default');
    if (!in_array($driver, ['tus', 'native'])) {
      throw new \RuntimeException("Invalid upload driver: {$driver}");
    }

    // Validasi Redis jika diperlukan
    if (config('upload.driver.tus.use_redis') && !extension_loaded('redis') && !class_exists(\Predis\Client::class)) {
      throw new \RuntimeException("Redis extension or Predis required for TUS Redis mode");
    }
  }

  private function ensureDirectories()
  {
    foreach (['tus', 'native'] as $type) {
      $path = config("upload.driver.{$type}.root");
      if (!is_dir($path)) {
        @mkdir($path, 0755, true);
      }
    }
  }

  // private function scheduleCleanup()
  // {
  //   // Cleanup async via queue
  //   if (app()->runningInConsole()) return;

  //   dispatch(new \Dochub\Job\UploadCleanupJob())
  //     ->onQueue('cleanup')
  //     ->delay(now()->addSeconds(30));
  // }


  // /**
  //  * Dapatkan instance Redis yang kompatibel
  //  * 
  //  * @return Cacheable|null
  //  */
  // private function getRedisCache(): ?Cacheable
  // {
  //   // 1. Coba ekstensi PHP Redis (tercepat)
  //   if (Tus::redis_client() === 'phpredis' && extension_loaded('redis')) {
  //     try {
  //       $redis = new \Redis();
  //       $redis->connect(
  //         Tus::redis_host(),
  //         Tus::redis_port(),
  //         Tus::redis_connection_timeout()
  //       );

  //       if (Tus::redis_username() && Tus::redis_password()) {
  //         $redis->auth(Tus::redis_username(), Tus::redis_password());
  //       } elseif (Tus::redis_password()) {
  //         $redis->auth(Tus::redis_password());
  //       }

  //       $redis->select(Tus::redis_database());

  //       // Tes koneksi
  //       $redis->ping();

  //       return $this->createTusRedisCache($redis);
  //     } catch (\Exception $e) {
  //       Log::warning("Redis extension failed: " . $e->getMessage());
  //     }
  //   }
  //   // 2. Fallback ke Predis (library Composer)
  //   else {
  //     try {
  //       $client = new \Predis\Client([
  //         'scheme' => 'tcp',
  //         'host'   => Tus::redis_host(),
  //         'port'   => Tus::redis_port(),
  //         'database' => Tus::redis_database(),
  //         'password' => Tus::redis_password(),
  //         'timeout' => 5.0,
  //       ]);

  //       // Tes koneksi
  //       $client->ping();

  //       return $this->createTusRedisCache($client);
  //     } catch (\Exception $e) {
  //       Log::warning("Predis failed: " . $e->getMessage());
  //     }
  //   }

  //   // 3. Fallback ke file cache (untuk development)
  //   Log::info("Using file cache for Tus (Redis not available)");
  //   return null; // Tus akan pakai file cache default
  // }

  // /**
  //  * Buat adapter cache yang kompatibel dengan Tus
  //  */
  // private function createTusRedisCache($redis): Cacheable
  // {
  //   // Jika pakai ekstensi Redis
  //   return new TusRedis($redis);

  //   throw new \RuntimeException("Unsupported Redis client");
  // }
}
