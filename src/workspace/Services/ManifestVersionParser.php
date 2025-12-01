<?php

namespace Dochub\Workspace\Services;

use Illuminate\Support\Carbon;

class ManifestVersionParser
{
  /** saat ini hanya support ISO 8601 saja */
  public static $method = 'ISO 8601'; // MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD]

  protected static $iso_format = "Y-m-d\TH:i:s.v\Z";

  public static function makeVersion()
  {
    return now()->format(self::$iso_format); // ISO 8601
  }

  public static function isValid(string $version, $method = 'ISO 8601')
  {
    return match ($method) {
      // 'ISO 8601' => Carbon::parse($version)->format('Y-m-d\TH:i:s.v\Z') === $version,
      'ISO 8601' => self::isIsoFormat($version),
      default => false,
    };
  }

  public static function timestampToIso9601(string $ts)
  {
    return Carbon::parse($ts)->format(self::$iso_format);
  }

  private static function isIsoFormat($ts)
  {
    $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    return preg_match($pattern, $ts) === 1 &&
      Carbon::createFromFormat('Y-m-d\TH:i:s.v\Z', $ts) !== false;
  }
}
