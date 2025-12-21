<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Workspace\Blob;
use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Workspace;
use Illuminate\Console\Command;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

// php artisan workspace:compare w:5 w:6

// # Output:
// 🔍 Perbandingan: w:5 vs w:6
// +------------------+--------+
// | Metrik           | Jumlah |
// +------------------+--------+
// | File di source   | 1,248  |
// | File di target   | 1,252  |
// | Sama             | 1,240  |
// | Berubah          | 5      |
// | Ditambahkan      | 4      |
// | Dihapus          | 1      |
// | Total perubahan  | 10     |
// +------------------+--------+

// 📋 Perubahan (maks 10 file):
// +-------+------------------------+---------+---------+
// | Aksi  | Path                   | Ukuran  | Preview |
// +-------+------------------------+---------+---------+
// | CH... | config/app.php         | +1.2K   | +3/-1   |
// | AD... | public/logo-new.png    | +15.4K  |         |
// | DE... | old-feature.js         | -2.1K   |         |
// +-------+------------------------+---------+---------+

// Integrasi dengan rollback
// # Rollback dulu, lalu bandingkan
// php artisan workspace:rollback 5 abc123-def456 --name="rollback-test"
// NEW_ID=$(php artisan workspace:rollback 5 abc123-def456 --json | jq -r '.new_workspace.id')
// php artisan workspace:compare w:5 w:$NEW_ID --limit=5

class WorkspaceCompareCommand extends Command
{
  protected $maxSizeBytesDiffPreview = 10240; // 10Kb

  protected $signature = 'workspace:compare 
                            {source : ID workspace/merge pertama (format: w:1 atau m:abc123)}
                            {target : ID workspace/merge kedua (format: w:2 atau m:def456)}
                            {--limit=10 : Maksimal file yang ditampilkan}
                            {--diff : Tampilkan diff untuk file teks <10KB}
                            {--json : Output JSON}';

  protected $description = 'Bandingkan dua workspace atau merge';

  public function handle()
  {
    $sourceSpec = $this->argument('source');
    $targetSpec = $this->argument('target');
    $limit = (int) $this->option('limit');
    $showDiff = $this->option('diff');
    $isJson = $this->option('json');

    try {
      [$sourceType, $sourceId] = $this->parseSpec($sourceSpec);
      [$targetType, $targetId] = $this->parseSpec($targetSpec);

      $sourceFiles = $this->getFiles($sourceType, $sourceId);
      $targetFiles = $this->getFiles($targetType, $targetId);

      $diff = $this->computeDiff($sourceFiles, $targetFiles);

      if ($isJson) {
        $this->outputJson($diff);
        return Command::SUCCESS;
      }

      // Tampilkan ringkasan
      $this->info("🔍 Perbandingan: {$sourceSpec} vs {$targetSpec}");
      $this->table(
        ['Metrik', 'Jumlah'],
        [
          ['File di source', count($sourceFiles)],
          ['File di target', count($targetFiles)],
          ['Sama', $diff['identical']],
          ['Berubah', $diff['changed']],
          ['Ditambahkan', $diff['added']],
          ['Dihapus', $diff['deleted']],
          ['Total perubahan', $diff['changed'] + $diff['added'] + $diff['deleted']],
        ]
      );

      // Tampilkan detail perubahan
      if ($diff['changes']->isNotEmpty()) {
        $this->newLine();
        $this->info("📋 Perubahan (maks {$limit} file):");

        $rows = [];
        foreach ($diff['changes']->take($limit) as $change) {
          $rows[] = [
            strtoupper($change['action']),
            $change['path'],
            $this->formatBytes($change['size_change'] ?? 0),
            $change['diff_preview'] ?? '',
          ];
        }

        $headers = ['Aksi', 'Path', 'Ukuran', 'Preview'];
        if (!$showDiff) {
          array_pop($headers); // Hapus kolom Preview
          foreach ($rows as &$row) {
            array_pop($row);
          }
        }

        $this->table($headers, $rows);

        if ($diff['changes']->count() > $limit) {
          $this->warn("... dan " . ($diff['changes']->count() - $limit) . " file lainnya");
        }
      }

      // Saran
      if ($diff['changed'] + $diff['added'] + $diff['deleted'] === 0) {
        $this->info("✅ Tidak ada perubahan — kedua versi identik.");
      } else {
        $this->info("💡 Tips: Gunakan --diff untuk lihat perubahan teks, atau --limit=100 untuk lebih banyak file.");
      }

      return Command::SUCCESS;
    } catch (\Exception $e) {
      if ($isJson) {
        $this->outputJson(['error' => $e->getMessage()]);
      } else {
        $this->error("❌ " . $e->getMessage());
      }
      return Command::FAILURE;
    }
  }

  private function parseSpec(string $spec): array
  {
    if (preg_match('/^workspace:(\d+)$/', $spec, $m)) {
      return ['workspace', (int) $m[1]];
    }
    else if (preg_match('/^manifest:(\d+)$/', $spec, $m)) {
      return ['manifest', (int) $m[1]];
    }
    else if (preg_match('/^merge:([a-f0-9\-]+)$/', $spec, $m)) {
      return ['merge', $m[1]];
    }
    throw new \InvalidArgumentException("Format tidak valid: {$spec}. Gunakan w:123 atau m:abc-def");
  }

  private function getFiles(string $type, $id): \Illuminate\Support\Collection
  {
    switch ($type) {
      case 'merge': 
        return File::where('merge_id', $id)->get();
      case 'workspace': 
        return File::where([
          'workspace_id' => $id,
          'merge_id' => Merge::where('workspace_id', $id)
            ->latest('merged_at')
            ->value('id')
        ])->get();
      case 'manifest':
        return File::where([
          'workspace_id' => Manifest::where('id', $id)->value('workspace_id'),
          'merge_id'=> Merge::where('manifest_hash', Manifest::where('id', $id)->value('hash_tree_sha256'))->value('id')
        ])->get();
      default:
        return throw new \RuntimeException("Tipe tidak dikenal: {$type}");
      }
  }

  /**
   * Di compute berdasarkan path file. 
   * Jika path berbeda meski blob sama berarti = di "added" / "delete"
   * Jika path sama dan blob sama berarti = identik
   * Otherwise = "updated"
   */
  private function computeDiff($sourceFiles, $targetFiles): array
  {
    $sourceMap = $sourceFiles->keyBy('relative_path');
    $targetMap = $targetFiles->keyBy('relative_path');

    $changes = collect();
    $identical = 0;
    $changed = 0;
    $added = 0;
    $deleted = 0;

    // File di target tapi tidak di source = added
    foreach ($targetMap as $path => $file) {
      if (!$sourceMap->has($path)) {
        $changes->push([
          'action' => 'added',
          'path' => $path,
          'blob' => $file->blob_hash, // blob ini sifatnya optional.
          'size_change' => $file->size_bytes,
        ]);
        $added++;
      }
    }

    // File di source
    foreach ($sourceMap as $path => $sourceFile) {
      // Dihapus
      if (!$targetMap->has($path)) {
        $changes->push([
          'action' => 'deleted',
          'path' => $path,
          'blob' => $file->blob_hash, // blob ini sifatnya optional.
          'size_change' => -$sourceFile->size_bytes,
        ]);
        $deleted++;
      } 
      else {
        $targetFile = $targetMap->get($path);
        // identik yaitu path dan blob SAMA
        if ($sourceFile->blob_hash === $targetFile->blob_hash) {
          $identical++;
        } else {
          // Berubah
          $sizeDiff = $targetFile->size_bytes - $sourceFile->size_bytes;
          $change = [
            'action' => 'changed',
            'path' => $path,
            'size_change' => $sizeDiff,
          ];

          // Tambahkan diff preview untuk file teks kecil (10 kb)
          if ($this->option('diff') && $targetFile->size_bytes < $this->maxSizeBytesDiffPreview) {
            $change['diff_preview'] = $this->generateDiffPreview(
              $sourceFile->blob_hash,
              $targetFile->blob_hash
            );
          }

          $changes->push($change);
          $changed++;
        }
      }
    }

    return [
      'identical' => $identical,
      'changed' => $changed,
      'added' => $added,
      'deleted' => $deleted,
      'changes' => $changes->sortBy('path'),
    ];
  }

  private function generateDiffPreview(string $oldHash, string $newHash): string
  {
    try {
      // $oldPath = storage_path("app/private/dochub/blobs/" . substr($oldHash, 0, 2) . "/{$oldHash}");
      // $oldPath = Workspace::blobPath() . "/" . substr($oldHash, 0, 2) . "/{$oldHash}";
      $oldPath = Blob::hashPath($oldHash);
      // $newPath = storage_path("app/private/dochub/blobs/" . substr($newHash, 0, 2) . "/{$newHash}");
      // $newPath = Workspace::blobPath() . "/" . substr($newHash, 0, 2) . "/{$newHash}";
      $newPath = Blob::hashPath($newHash);

      if (!file_exists($oldPath) || !file_exists($newPath)) {
        return "[file tidak ditemukan]";
      }

      // Baca isi (hanya untuk file kecil)
      $oldContent = file_get_contents($oldPath);
      $newContent = file_get_contents($newPath);

      // Gunakan sebastian/diff jika tersedia
      if (class_exists(\SebastianBergmann\Diff\Differ::class)) {
        $differ = new \SebastianBergmann\Diff\Differ(new UnifiedDiffOutputBuilder());
        $diff = $differ->diff($oldContent, $newContent);
        return $this->summarizeDiff($diff);
      }

      return "[ubah " . strlen($newContent) . " byte]";
    } catch (\Exception $e) {
      return "[diff error]";
    }
  }

  private function summarizeDiff(string $diff): string
  {
    $lines = explode("\n", $diff);
    $adds = 0;
    $dels = 0;

    foreach ($lines as $line) {
      if (str_starts_with($line, '+') && !str_starts_with($line, '++')) $adds++;
      if (str_starts_with($line, '-') && !str_starts_with($line, '--')) $dels++;
    }

    return "+{$adds}/-{$dels}";
  }

  private function formatBytes(int $bytes): string
  {
    if ($bytes === 0) return '0';
    if ($bytes < 0) return '-' . $this->formatBytes(abs($bytes));

    if ($bytes < 1024) return $bytes . 'B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . 'K';
    return round($bytes / 1048576, 1) . 'M';
  }

  private function outputJson(array $data): void
  {
    $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }
}
