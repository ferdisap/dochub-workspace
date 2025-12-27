<?php

namespace Dochub\Controller;

use Closure;
use Dochub\Encryption\EncryptStatic;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Merge as WorkspaceMerge;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Services\ManifestVersionParser;
use Dochub\Workspace\Workspace as DochubWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

class WorkspaceController
{
  public function analyzeView(Request $request)
  {
    return view('vendor.dochub.workspace.analyze.app', [
      'user' => $request->user()
    ]);
  }

  public function detail(Request $request, Workspace $workspace)
  {
    // $path = "D:/data_ferdi/application/S1000D/apps/contoh_storage/AFMS-Cessna172_v2_update9.docx";
    // $pathEdit1 = "D:/data_ferdi/application/S1000D/apps/contoh_storage/AFMS-Cessna172_v2_update9_edit1.docx";
    // $pathCopy1 = "D:/data_ferdi/application/S1000D/apps/contoh_storage/AFMS-Cessna172_v2_update9_copy1.docx";

    // $fileId = EncryptStatic::deriveFileIdBin($path, "1");
    // $fileIdEdit1 = EncryptStatic::deriveFileIdBin($pathEdit1, "1");
    // $fileIdCopy1 = EncryptStatic::deriveFileIdBin($pathCopy1, "1");

    // dd($fileId, $fileIdEdit1, $fileIdCopy1);

    // $path = "D:/data_ferdi/application/S1000D/apps/contoh_storage/test2.xml";
    // $pathEdit1 = "D:/data_ferdi/application/S1000D/apps/contoh_storage/test2_edit1.xml";
    // $fileId = EncryptStatic::deriveFileIdBin($path, "1");
    // $fileIdEdit1 = EncryptStatic::deriveFileIdBin($pathEdit1, "1");
    // dd($fileId, $fileIdEdit1, $fileId === $fileIdEdit1);

    $path = "D:/data_ferdi/application/S1000D/apps/contoh_storage/test3.xml";
    $pathEdit1 = "D:/data_ferdi/application/S1000D/apps/contoh_storage/test3_edit1.xml";
    $fileId = EncryptStatic::deriveFileIdBin($path, "1");
    $fileIdEdit1 = EncryptStatic::deriveFileIdBin($pathEdit1, "1");
    dd($fileId, $fileIdEdit1, $fileId === $fileIdEdit1);

    // dd($workspace->merges[0]->id); // 4189e063-e286-410f-9e5e-6b556d99b613
    // dd($workspace->merges[0]->files[0]->relative_path);
    dd($workspace->merges[0]->previousMerge);
  }

  /**
   * contoh output
   * {
   *  "workspaces": [
   *    {
   *      "name": "contoh-1",
   *      "visibility": "private",
   *      "created_at": "2025-12-24T10:08:42.000000Z",
   *      "updated_at": "2025-12-24T10:08:42.000000Z",
   *      "manifests": [
   *        {
   *          "content": {
   *            "hash_tree_sha256": "4f2bfa0ab9effa4de313f11bb3859fb0ab558d00ec978658ae9bdc874e4284ff",
   *            "tags": null,
   *            "source": "upload:user-07cbf89de22ff941aac4c414a1c861714d8b0b8ae658df8ac59abf85079c3b8b",
   *            "version": "2025-12-26T14:26:58.720Z",
   *            "total_files": 1,
   *            "total_size_bytes": 13,
   *            "files": [
   *              {
   *                "relative_path": "contoh-1\/contoh-file-1.txt",
   *                "sha256": "748180ae02ecc905a269b076e14c8dbfa0431f2c124375f45b7b0326c4075e6c",
   *                "size_bytes": 13,
   *                "file_modified_at": 1766569111
   *              }
   *            ]
   *          },
   *          "created_at": "2025-12-26T14:26:59.000000Z",
   *          "updated_at": "2025-12-26T14:26:59.000000Z"
   *        }
   *      ]
   *    }
   *  ]
   * }
   */
  public function search(Request $request)
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
                if (!(WorkspaceMerge::isValidLabel($match[2]))) $fail('{$attribute} value type must be ' . Merge::getLabelPattern() . " and max. length is " . Merge::getMaxLengthLabel()) . " character";
                break;
            }
            $qTypes[] = $match[1];
            $qStrings[] = $match[2];
          }
        },
      ],
    ]);

    $workspaceModels = Workspace::with([
      "manifests" => fn(HasMany $qry): HasMany => $qry->latest('created_at')->limit(5),
    ])->whereNull('deleted_at')->where("owner_id", $request->user()->id);

    foreach ($qTypes as $k => $qType) {
      $qString = $qStrings[$k];
      (match ($qType) {
        'name' => $workspaceModels->where('name', 'LIKE', '%' . $qString . '%'),
        'version' => $workspaceModels->whereHas('manifests', function (Builder $query) use ($qType, $qString) {
          $query->where($qType, 'LIKE', '%' . $qString . '%')->latest('created_at')->limit(5);
        }),
        'source' => $workspaceModels->whereHas('manifests', function (Builder $query) use ($qType, $qString) {
          $query->where($qType, 'LIKE', '%' . $qString . '%')->latest('created_at')->limit(5);
        }),
        'hash' => $workspaceModels->whereHas('manifests', function (Builder $query) use ($qType, $qString) {
          $query->where($qType, $qString)->latest('created_at')->limit(5);
        }),
        'label' => $workspaceModels->whereHas('manifests', function (Builder $query) use ($qType, $qString) {
          $query->whereHas('merge', function (Builder $q) use ($qString) {
            $q->where('label', 'LIKE', '%' . $qString . '%');
          })->latest('created_at')->limit(5);
        }),
      });
    }

    $workspaceModels->limit(5);
    $workspaceModels = $workspaceModels->get()->map(function ($workspaceModel) {
      $workspaceModel->makeHidden(['id', 'owner_id', 'deleted_at']);
      return [
        'name' => $workspaceModel->name,
        'visibility' => $workspaceModel->visibility,
        'created_at' => $workspaceModel->created_at,
        'updated_at' => $workspaceModel->updated_at,        
        'manifests' => $workspaceModel->manifests->map(fn($m) => [
          'content' => $m->content,
          'created_at' => $m->created_at,
          'updated_at' => $m->updated_at,
        ])
      ];
    });
    
    return response([
      "workspaces" => $workspaceModels
    ]);
    
  }

  public function tree(Request $request, Workspace $workspace)
  {
    $merges = $workspace->merges()->get(['dochub_merges.id', 'dochub_merges.prev_merge_id', 'merged_at', 'label', 'message']);
    $mergeMap = [];
    $childrenMap = []; // id → [child_id1, child_id2, ...]

    foreach ($merges as $m) {
      $id = $m->id;
      $prev = $m->prev_merge_id;

      $mergeMap[$id] = $m;
      $childrenMap[$id] = $childrenMap[$id] ?? [];

      if ($prev) {
        $childrenMap[$prev] = $childrenMap[$prev] ?? [];
        $childrenMap[$prev][] = $id;
      }
    }

    $roots = $merges->filter(fn($m) => $m->prev_merge_id === null)->pluck('id')->all();

    $treeData = [];
    foreach ($roots as $rootId) {
      $tree = $this->buildMergeTree($rootId, $mergeMap, $childrenMap);
      if ($tree) $treeData[] = $tree;
    }

    return response()->json([
      "tree" => $treeData,
    ]);
  }

  private function buildMergeTree($nodeId, $mergeMap, $childrenMap, $maxDepth = 100)
  {
    if (!$nodeId || !isset($mergeMap[$nodeId]) || $maxDepth <= 0) return null;

    $merge = $mergeMap[$nodeId];
    $children = [];

    foreach ($childrenMap[$nodeId] ?? [] as $childId) {
      $child = $this->buildMergeTree($childId, $mergeMap, $childrenMap, $maxDepth - 1);
      if ($child) $children[] = $child;
    }

    return [
      'id' => $merge->id,
      'label' => $merge->label ?: substr($merge->id, 0, 8),
      'merged_at' => $merge->merged_at->toIso8601String(),
      'message' => $merge->message,
      'children' => $children,
    ];
  }

  public function blank(Request $request)
  {
    $request->validate([
      "name" => ["required", "alpha_num", "unique:dochub_workspaces,name"],
      "visibility" => ["in:private,public"]
    ]);

    $workspace = Workspace::create([
      "owner_id" => $request->user()->id,
      "name" => $request->get('name'),
    ]);

    // Buat folder fisik (opsional)
    @mkdir(DochubWorkspace::path() . "/{$workspace->name}", 0755, true);

    return Response::make([
      "workspace" => [
        "name" => $workspace->name,
        "visibility" => $workspace->visibility,
      ]
    ], 200, [
      "content-type" => "application/json"
    ]);
  }

  public function clone(Request $request, Workspace $workspace)
  {
    $request->validate([
      "name" => ["required", "alpha_num", "unique:dochub_workspaces,name"],
      "visibility" => ["in:private,public"]
    ]);
    // Dapatkan merge terakhir
    $latestMerge = Merge::where('workspace_id', $workspace->id)
      ->latest('merged_at')
      ->firstOrFail();

    $newWorkspace = $workspace->replicate();
    $newWorkspace->name = $request->get('name');
    $newWorkspace->save();

    // Salin file dari merge terakhir
    foreach ($latestMerge->files as $file) {
      $file->replicate()->fill(['workspace_id' => $newWorkspace->id])->save();
    }

    // Catat sebagai clone (bukan rollback)
    MergeSession::create([
      'target_workspace_id' => $newWorkspace->id,
      'source_identifier' => "clone:{$workspace->id}",
      'source_type' => 'clone',
      'status' => 'applied',
      'metadata' => ['source_workspace_id' => $workspace->id],
    ]);

    return Response::make([
      "workspace" => [
        "name" => $newWorkspace->name,
        "visibility" => $newWorkspace->visibility,
      ]
    ], 200, [
      "content-type" => "application/json"
    ]);
  }
}
