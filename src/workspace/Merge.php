<?php

namespace Dochub\Workspace;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class Merge
{
  public static function getLabelPattern()
  {
    return '/[a-zA-Z0-9\.]/';
  }

  private static function getUnallowedLablePattern()
  {
    return '/[^a-zA-Z0-9\.]/';
  }

  public static function getMaxLengthLabel()
  {
    return 16;
  }

  public static function isValidLabel(string $label): bool
  {
    return (preg_match(self::getLabelPattern(), $label)) && (strlen($label) <= 16);
  }

  public static function cleanLabel(string $label)
  {
    if (!self::isValidLabel($label)) {
      $new_string = preg_replace(self::getUnallowedLablePattern(), "", $label);
      return substr($new_string, 0, self::getMaxLengthLabel());
    } 
    return $label;
  }
}
