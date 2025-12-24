<?php

namespace Dochub\Controller;

use Closure;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Merge;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Services\ManifestVersionParser;
use Dochub\Workspace\Workspace as DochubWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ManifestController
{
  public function getManifests(Request $request)
  {
    $manifestModels = Manifest::where('from_id', (string) $request->user()->id);

    // jika ada pencarian berdasarkan tags
    $tags = $request->get('tags');
    if ($tags) $manifestModels->where('tags', 'LIKE', "%{$tags}%");
    $manifestModels = $manifestModels->get(['storage_path']);

    $manifestModels = collect($manifestModels)->map(function ($model) {
      return $model->content;
    });

    return response([
      "manifests" => $manifestModels
    ]);
  }

  // http://localhost:1001/dochub/manifest/search?query=hash:aa4d977d2bf8d2775ae3c2fc93e97d2455f4ff52f8b082b1e24f86bd7eb18ba7
  // http://localhost:1001/dochub/manifest/search?query=source:upload:user-d4735e3a265e16eee03f59718b9b5d03019c07d8b6c51f90da3a666eec13ab35
  // return 422 jika "X-Requested-With": "XMLHttpRequest", atau 302 found (redirect)
  public function searchManifests(Request $request)
  {
    $qType = null;
    $qString = null;
    $request->validate([
      "query" => [
        'required',
        function (string $attribute, mixed $value, Closure $fail) use (&$qType, &$qString) {
          $re = '/(name|version|source|hash|label):(.+)/';
          preg_match($re, $value, $matches, PREG_OFFSET_CAPTURE, 0);
          if (!(isset($matches[1][0]) && isset($matches[2][0]))) {
            $fail("{$attribute} value not suitable with {$re}");
          }
          if (count($matches)) {
            $type = $matches[1][0];
            $qVal = $matches[2][0];
            switch ($type) {
              case 'name':
                if (!(DochubWorkspace::isValidName($qVal))) $fail("{$attribute} value type must be " . DochubWorkspace::getNamePattern() . " and max. length is " . DochubWorkspace::getMaxLengthName()) . " character";
                break;
              case 'version':
                if (!(ManifestVersionParser::isValid($qVal))) $fail('{$attribute} value type must be ' . ManifestVersionParser::getPattern());
                break;
              case 'source':
                if (!(ManifestSourceType::isValid($qVal))) $fail('{$attribute} value type must be ' . ManifestSourceType::getPattern());
                break;
              case 'hash':
                if ((strlen($qVal) !== 64)) $fail('{$attribute} value type must be 64 length lower case letter [a-z]');
                break;
              case 'label':
                if (!(Merge::isValidLabel($qVal))) $fail('{$attribute} value type must be ' . Merge::getLabelPattern() . " and max. length is " . Merge::getMaxLengthLabel()) . " character";
                break;
            }
            $qType = $type;
            $qString = $qVal;
          }
        },
      ],
    ]);

    $manifestModels = (match ($qType) {
      'name' => Manifest::where('workspace_id', Workspace::where('name', $qString)->where("owner_id", $request->user()->id)->value('id'))->first(),
      'version' => Manifest::where('version', $qString)->first(),
      'source' => Manifest::where('source', $qString)->first(),
      'hash' => Manifest::where('hash_tree_sha256', $qString)->first(),
      'label' => Manifest::whereHas('merge', function (Builder $query) use ($qString) {
        $query->where('label', $qString);
      })
    })
      ->where('from_id', $request->user()->id)
      ->latest('created_at')  // Orders by 'created_at' DESC
      ->limit(5)
      ->get();

    $manifestModels = $manifestModels->map(function($manifestModel) {
      return $manifestModel->content;
    });

    if ($manifestModels) {
      return response([
        "manifests" => $manifestModels
      ]);
    } else {
      return response(null, 404);
    }
  }
}
