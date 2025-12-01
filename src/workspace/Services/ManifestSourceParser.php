<?php

namespace Dochub\Workspace\Services;

use Dochub\Workspace\Enums\ManifestSourceType;
use InvalidArgumentException;

class ManifestSourceParser
{
  /**
   * Pola regex untuk validasi
   */
  private const TYPE_PATTERN = '/^[a-z]+$/';
  private const IDENTIFIER_PATTERN = '/^[a-z0-9-]+$/';
  private const ENV_PATTERN = '/^(prod|staging|dev|test)$/';
  private const VERSION_PATTERN = '/^v?\d+(\.\d+)*[a-z0-9-]*$/i';

  /**
   * Buat source string dari komponen
   * 
   * @param string $type
   * @param string $identifier
   * @param string|null $environment
   * @param string|null $version
   * @return string
   * @throws InvalidArgumentException
   */
  public static function makeSource(
    string $type,
    string $identifier,
    ?string $environment = null,
    ?string $version = null
  ): string {
    // Validasi type
    // if (!preg_match(self::TYPE_PATTERN, $type)) {
    if (!(ManifestSourceType::isValid($type))) {
      throw new InvalidArgumentException(
        "Invalid type '{$type}'. Must be lowercase letters only (e.g., 'cms', 'api')."
      );
    }

    // Validasi identifier
    if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
      throw new InvalidArgumentException(
        "Invalid identifier '{$identifier}'. Must be lowercase alphanumeric with hyphens (e.g., 'client-a')."
      );
    }

    // Validasi environment
    if ($environment && !preg_match(self::ENV_PATTERN, $environment)) {
      throw new InvalidArgumentException(
        "Invalid environment '{$environment}'. Must be 'prod', 'staging', 'dev', or 'test'."
      );
    }

    // Validasi version
    if ($version && !preg_match(self::VERSION_PATTERN, $version)) {
      throw new InvalidArgumentException(
        "Invalid version '{$version}'. Must be like 'v1', '2.1.0', 'rc-1'."
      );
    }

    // Bangun string
    // $parts = [$type, $identifier];
    $parts = [$identifier];

    if ($environment) {
      $parts[] = $environment;
    }

    if ($version) {
      $parts[] = $version;
    }

    return implode(':', [$type, implode('-', $parts)]);
  }

  /**
   * Parse source string menjadi komponen
   * 
   * @param string $source
   * @return array{type: string, identifier: string, environment: ?string, version: ?string}
   * @throws InvalidArgumentException
   */
  public static function parseSource(string $source): array
  {
    // Validasi format dasar
    if (!preg_match('/^[a-z]+:[a-z0-9-]+(?:-[a-z0-9-]+)*$/', $source)) {
      throw new InvalidArgumentException(
        "Invalid source format. Expected 'type:identifier[-env][-ver]' (e.g., 'cms:client-a-prod-v1')."
      );
    }

    // Split type dan rest
    $parts = explode(':', $source, 2);
    if (count($parts) !== 2) {
      throw new InvalidArgumentException("Source must contain exactly one ':'");
    }

    $type = $parts[0];
    $rest = $parts[1];
    $segments = explode('-', $rest);

    if (count($segments) < 1) {
      throw new InvalidArgumentException("Identifier cannot be empty");
    }

    // Inisialisasi
    $identifier = $segments[0];
    $environment = null;
    $version = null;

    // Proses dari belakang (versi biasanya di akhir)
    $remaining = array_slice($segments, 1); // Segmen setelah identifier

    // Cek bagian terakhir untuk version
    if (!empty($remaining)) {
      $last = end($remaining);
      if (preg_match(self::VERSION_PATTERN, $last)) {
        $version = $last;
        array_pop($remaining);
      }
    }

    // Cek bagian terakhir untuk environment
    if (!empty($remaining)) {
      $last = end($remaining);
      if (preg_match(self::ENV_PATTERN, $last)) {
        $environment = $last;
        array_pop($remaining);
      }
    }

    // Gabungkan sisa sebagai bagian identifier
    if (!empty($remaining)) {
      $identifier .= '-' . implode('-', $remaining);
    }

    // Validasi akhir
    // if (!preg_match(self::TYPE_PATTERN, $type)) {
    if(!ManifestSourceType::isValid($type)){
      throw new InvalidArgumentException("Invalid type in parsed source");
    }

    if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
      throw new InvalidArgumentException("Invalid identifier in parsed source");
    }

    return compact('type', 'identifier', 'environment', 'version');
  }

  /**
   * Helper: Cek apakah source valid
   */
  public static function isValid(string $source): bool
  {
    try {
      self::parseSource($source);
      return true;
    } catch (InvalidArgumentException $e) {
      return false;
    }
  }

  /**
   * Helper: Dapatkan environment dari source
   */
  public static function getEnvironment(string $source): ?string
  {
    return self::parseSource($source)['environment'];
  }

  /**
   * Helper: Dapatkan version dari source
   */
  public static function getVersion(string $source): ?string
  {
    return self::parseSource($source)['version'];
  }
}
