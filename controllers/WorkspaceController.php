<?php

namespace Dochub\Controller;

use Dochub\Encryption\EncryptStatic;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Workspace as DochubWorkspace;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

class WorkspaceController
{
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
