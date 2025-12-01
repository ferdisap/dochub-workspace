<?php

namespace Dochub\Upload;

class EnvironmentDetector
{
  private static ?string $cachedEnv = null;

  public static function getEnvironment(): string
  {
    return self::$cachedEnv ??= self::detectEnvironment();
  }

  public static function detectEnvironment(): string
  {
    // Force override
    if ($forced = env('FORCE_ENVIRONMENT')) {
      return $forced;
    }

    // deteksi development (php artisan serve)
    if (php_sapi_name() === 'cli-server') {
      return 'development';
    }

    // Deteksi shared hosting
    if (self::isSharedHosting()) {
      return 'shared';
    }

    // Deteksi serverless
    if (self::isServerless()) {
      return 'serverless';
    }

    // Deteksi container
    if (self::isContainer()) {
      return 'container';
    }

    return 'dedicated';
  }

  private static function isSharedHosting(): bool
  {
    $indicators = [
      // File khas shared hosting
      '/etc/virtualmin' => true,
      '/usr/local/ispmgr' => true,

      // User khas
      'USER' => ['www-data', 'apache', 'nginx', 'nobody'],

      // Batasan resource
      // 'memory_limit' => fn($v) => (int) str_replace('M', '', $v) < 512,
      'memory_limit' => fn($v) => self::parseMemoryLimit(ini_get('memory_limit')) < 512 * 1024 * 1024,
      'max_execution_time' => fn($v) => $v < 300,
    ];

    foreach ($indicators as $key => $check) {
      if (is_string($key) && $key === 'USER') {
        if (in_array(get_current_user(), $check)) return true;
      } elseif (is_string($key) && in_array($key, ['memory_limit', 'max_execution_time'])) {
        $value = (int) ini_get($key);
        if ($check($value)) return true;
      } elseif (is_file($key)) {
        return true;
      }
    }

    return false;
  }

  private static function parseMemoryLimit(string $limit): int
  {
    $limit = trim($limit);
    if ($limit === '-1') return PHP_INT_MAX;

    $unit = strtoupper(substr($limit, -1));
    $value = (int) $limit;

    return match ($unit) {
      'G' => $value * 1024 * 1024 * 1024,
      'M' => $value * 1024 * 1024,
      'K' => $value * 1024,
      default => $value, // assume bytes
    };
  }

  private static function isServerless(): bool
  {
    $envs = [
      'AWS_LAMBDA_FUNCTION_NAME',
      'GOOGLE_CLOUD_PROJECT',
      'VERCEL',
      'NETLIFY',
      'HEROKU_APP_NAME',
    ];

    foreach ($envs as $env) {
      if (getenv($env) || ($_SERVER[$env] ?? null)) {
        return true;
      }
    }

    return false;
  }

  private static function isContainer(): bool
  {
    // Cek cgroups
    if (is_file('/proc/1/cgroup')) {
      $cgroups = file_get_contents('/proc/1/cgroup');
      if (
        strpos($cgroups, 'docker') !== false ||
        strpos($cgroups, 'lxc') !== false
      ) {
        return true;
      }
    }

    if (file_exists('/.dockerenv') || getenv('KUBERNETES_SERVICE_HOST')) {
      return true;
    }

    // Cek env khas container
    return getenv('CONTAINER') === 'true' ||
      getenv('KUBERNETES_SERVICE_HOST') !== false;
  }

  // public static function getUploadStrategy(): string
  // {    
  //   return match (self::getEnvironment()) {
  //     'shared', 'serverless' => 'native',
  //     default => 'tus',
  //   };
  // }
  public static function getUploadStrategy(): string
  {
    return config("upload.strategy." . self::getEnvironment(), 'native');
  }

  public static function getRecommendedConfig(): array
  {
    return match (self::getEnvironment()) {
      'shared' => [
        'max_size' => 100 * 1024 * 1024, // 100 MB // maximum total file upload
        'chunk_size' => 5 * 1024 * 1024, // 5 MB
        'timeout' => 300, // job queue timeout
      ],
      'serverless' => [
        'max_size' => 50 * 1024 * 1024, // 50 MB // maximum total file upload
        'chunk_size' => 1 * 1024 * 1024, // 1 MB
        'timeout' => 30, // job queue timeout
      ],
      'container', 'dedicated', 'development' => [
        'max_size' => 5 * 1024 * 1024 * 1024, // 5 GB // maximum total file upload
        'chunk_size' => 10 * 1024 * 1024, // 10 MB
        'timeout' => 3600, // job queue timeout
      ],
    };
  }
}
