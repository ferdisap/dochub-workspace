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
  /**
   * contoh response
   * {
   *  "manifests": [
   *    {
   *      "workspace": {
   *        "name": "contoh-1",
   *        "visibility": "private",
   *        "created_at": "2025-12-24T10:08:42.000000Z",
   *        "updated_at": "2025-12-24T10:08:42.000000Z"
   *      },
   *      "manifest": {
   *        "hash_tree_sha256": "256b15cea6ca2a231adcf9cef0e37cad14b1940b6afd3af664649f8850408c18",
   *        "tags": null,
   *        "source": "upload:user-d4735e3a265e16eee03f59718b9b5d03019c07d8b6c51f90da3a666eec13ab35",
   *        "version": "2025-12-24T10:40:56.710Z",
   *        "total_files": 1,
   *        "total_size_bytes": 13,
   *        "files": [
   *          {
   *            "relative_path": "contoh-1\\contoh-file-1.txt",
   *            "sha256": "748180ae02ecc905a269b076e14c8dbfa0431f2c124375f45b7b0326c4075e6c",
   *            "size_bytes": 13,
   *            "file_modified_at": 1766569110
   *          }
   *        ]
   *      }
   *    }
   *  ]
   * }
   */
  public function searchManifests(Request $request)
  {
    $qTypes = [];
    $qStrings = [];
    $request->validate([
      "query" => [
        'required',
        function (string $attribute, mixed $value, Closure $fail) use (&$qTypes, &$qStrings) {
          // eg:  ?query=label:v1.0.0,name:workspace_satu
          $re = '/([^:&,]+):([^:&,]+)/';
          preg_match_all($re, $value, $matches, PREG_SET_ORDER, 0);
          if (!$matches || count($matches) < 1) {
            $fail("{$attribute} value not suitable with {$re}");
          }
          foreach ($matches as $match) {
            if (!((isset($match[0])) && (isset($match[1]) && isset($match[2])))) {
              $fail("{$attribute} value not suitable with {$re}");
              return;
            }
            // validating key query
            if (!(in_array($match[1], ['name', 'version', 'source', 'hash', 'label']))) {
              $fail("{$attribute} should be name, version source, hash, or label");
              return;
            }
            // validating value query
            switch ($match[1]) {
              case 'name':
                if (!(DochubWorkspace::isValidName($match[2]))) $fail("{$attribute} value type must be " . DochubWorkspace::getNamePattern() . " and max. length is " . DochubWorkspace::getMaxLengthName()) . " character";
                break;
              case 'version':
                if (!(ManifestVersionParser::isValid($match[2]))) $fail('{$attribute} value type must be ' . ManifestVersionParser::getPattern());
                break;
              case 'source':
                if (!(ManifestSourceType::isValid($match[2]))) $fail('{$attribute} value type must be ' . ManifestSourceType::getPattern());
                break;
              case 'hash':
                if ((strlen($match[2]) !== 64)) $fail('{$attribute} value type must be 64 length lower case letter [a-z]');
                break;
              case 'label':
                if (!(Merge::isValidLabel($match[2]))) $fail('{$attribute} value type must be ' . Merge::getLabelPattern() . " and max. length is " . Merge::getMaxLengthLabel()) . " character";
                break;
            }
            $qTypes[] = $match[1];
            $qStrings[] = $match[2];
          }
        },
      ],
    ]);

    $manifestModels = Manifest::with('workspace')->where('from_id', $request->user()->id)->latest('created_at')->limit(5);  // Orders by 'created_at' DESC
    
    foreach ($qTypes as $k => $qType) {
      $qString = $qStrings[$k];
      (match ($qType) {
        'name' => $manifestModels->where('workspace_id', Workspace::where('name', $qString)->where("owner_id", $request->user()->id)->value('id'))->first(),
        'version' => $manifestModels->where('version', $qString)->first(),
        'source' => $manifestModels->where('source', $qString)->first(),
        'hash' => $manifestModels->where('hash_tree_sha256', $qString)->first(),
        'label' => $manifestModels->whereHas('merge', function (Builder $query) use ($qString) {
          $query->where('label', $qString);
        })
      });
    }
    $manifestModels = $manifestModels->get();
    $workspaces = [];
    $manifestModels = $manifestModels->map(function ($manifestModel) use(&$workspaces) {
        $workspaces[] = [
          "name" => $manifestModel->workspace->name,
          "visibility" => $manifestModel->workspace->visibility,
          "created_at" => $manifestModel->workspace->created_at,
          "updated_at" => $manifestModel->workspace->updated_at,
        ];
      // return [
      //   'workspace' => [
      //     "name" => $manifestModel->workspace->name,
      //     "visibility" => $manifestModel->workspace->visibility,
      //     "created_at" => $manifestModel->workspace->created_at,
      //     "updated_at" => $manifestModel->workspace->updated_at,
      //   ],
      //   'manifest' => 
      // ];
      return $manifestModel->content
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
