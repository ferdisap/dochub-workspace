<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('dochub_files', function (Blueprint $table) {
      $table->id();
      // $table->foreignUuid('merge_id')->constrained('dochub_merges'); // state di merge ini
      // $table->foreignId('workspace_id')->constrained('dochub_workspaces');
      $table->foreignUuid('merge_id'); // state di merge ini
      $table->foreignId('workspace_id');

      $table->string('relative_path'); // e.g., 'config/app.php'
      $table->string('blob_hash', 64); // isi file → pointer ke blobs/

      // opsional: untuk lacak perubahan
      $table->string('old_blob_hash', 64)->nullable(); // null = added

      // Jika action = 'deleted' → hapus file dari workspace live.
      // Jika action = 'added' → abaikan (karena di M2 belum ada).
      // Jika action = 'updated' → symlink blobs/{$file->blob_hash} ke $path.
      $table->string('action')->default('updated') // 'added', 'updated', 'deleted'
        ->comment("How this file changed in this merge");

      $table->unsignedBigInteger('size_bytes');
      $table->timestamp('file_modified_at'); // mtime dari file asli

      $table->unique(['merge_id', 'relative_path']); // pastikan tidak duplikat path per merge
      $table->index(['workspace_id', 'relative_path']);
      $table->index('blob_hash');
    });
    
    // filenya tidak benar-benar ada. 
    // Path ini relative to storage/app/private/dochub/repository/<repository_name>
    // Path ini sama dengan blob path
    // $table->string('path')->unique(); 
    // $table->number('repository_id');
    // $table->string('compressed_path')->nullable(); // relative to <repo_name>/.repo/merge/<merge_id>
    // $table->timestamp('last_modified'); // date UTC 0
    // $table->unsignedBigInteger('size'); // bytes
    // Schema::create('workspace_files', function (Blueprint $table) {
    //   $table->id();
    //   $table->foreignId('workspace_id')->constrained('workspaces');
    //   $table->foreignId('snapshot_id')->nullable()->constrained('workspace_snapshots');
    //   // null = current/live version (not archived yet)

    //   $table->string('relative_path');       // e.g., 'config/app.php'
    //   $table->string('hash_sha256');         // unique content identifier → deduplication!
    //   $table->unsignedBigInteger('size_bytes');
    //   $table->timestamp('last_modified_at'); // dari file mtime
    //   $table->json('metadata')->nullable();  // permission, mime, etc.

    //   $table->index(['workspace_id', 'relative_path']);
    //   $table->index('hash_sha256'); // untuk deduplication
    //   $table->timestamps();
    // });

    /**
     * Untuk action = 'updated': restore old_hash ke workspace.
     * Untuk action = 'deleted': restore file dari backup (karena tidak ada di workspace sekarang).
     * Untuk action = 'added': hapus file dari workspace. 
     * graph LR
     * A[User: rollback ke merge_id=42] --> B[Ambil record merge_sessions#42]
     * B --> C[Ambil semua merge_files WHERE merge_session_id=42]
     * C --> D{Untuk tiap file}
     * D -->|action=updated| E[Restore old_hash dari blobs/ atau dari backup archive]
     * D -->|action=deleted| F[Ekstrak file dari merge-42.tar.gz → tempatkan di workspace]
     * D -->|action=added| G[Hapus file dari workspace]
     * E --> H[Update workspace_files]
     * F --> H
     * G --> H
     * H --> I[Selesai — workspace kembali ke state sebelum merge #42]
     */

    // Schema::create('merge_files', function (Blueprint $table) {
    //   $table->id();
    //   $table->foreignId('merge_session_id')->constrained();
    //   $table->string('relative_path');
    //   $table->string('action'); // 'updated', 'added', 'deleted'
    //   $table->string('old_hash')->nullable();  // hash versi lama (null jika added)
    //   $table->string('new_hash')->nullable();  // hash versi baru (null jika deleted)
    //   $table->unsignedBigInteger('old_size')->nullable();
    //   $table->unsignedBigInteger('new_size')->nullable();
    // });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('dochub_files');
  }
};
