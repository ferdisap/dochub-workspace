<?php

namespace Dochub\Workspace\Enums;

enum ManifestSourceType: string
{
  case CMS = 'cms';
  case API = 'api';
  case UPLOAD = 'upload';
  case CI = 'ci';
  case BACKUP = 'backup';
  case MIGRATION = 'migration';

  public static function isValid(string $type): bool
  {
    return in_array($type, self::values());
    // $parts = explode(':', $type, 2);
    // return count($parts) === 2 &&
    //   in_array($parts[0], self::values());
  }

  public static function values(): array
  {
    return array_column(self::cases(), 'value');
  }

  public static function getPattern()
  {
    return "/" . join("|", self::values()) . "/";
  }

  // public static function make(self $source, string $identifier, ?string $environment = null, ?string $version = null):string
  // {
  //   $src = "{$source}:{$identifier}";
  //   if($environment && $version){
  //     $src .= "-{$environment}-{$version}";
  //   } elseif($environment){
  //     $src .= "-{$environment}";
  //   }
  //   return $src;
  // }
}
