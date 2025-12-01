<?php

namespace Dochub\Controller;

use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule; // Import the Rule facade

class WorkspaceFromZipController
{
  public function importFromZip(string $zipPath, string $name, int $ownerId)
{
    $workspace = Workspace::create([
        'name' => $name,
        'owner_id' => $ownerId,
    ]);

    // Buat merge baru
    $merge = Merge::create([
        'id' => Str::uuid(),
        'workspace_id' => $workspace->id,
        'label' => 'initial-import',
    ]);

    $tempDir = sys_get_temp_dir() . '/import_' . uniqid();
    mkdir($tempDir);

    try {
        // Ekstrak
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $zip->extractTo($tempDir);
        $zip->close();

        // Proses tiap file
        $files = $this->scanDirectory($tempDir);
        foreach ($files as $relativePath => $filePath) {
            $hash = $this->blobLocalStorage->store($filePath);
            
            DochubFile::create([
                'merge_id' => $merge->id,
                'workspace_id' => $workspace->id,
                'relative_path' => $relativePath,
                'blob_hash' => $hash,
                'size_bytes' => filesize($filePath),
                'file_modified_at' => now(),
            ]);
        }

        // Session audit
        // DochubMergeSession::create([...]);

        return $workspace;

    } finally {
        $this->deleteDirectory($tempDir);
    }
  }  
}
