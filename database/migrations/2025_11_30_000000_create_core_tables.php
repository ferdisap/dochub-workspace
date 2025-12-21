<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up()
  {
    // Workspace
    Schema::create('dochub_workspaces', function (Blueprint $table) {
      $table->id();
      $table->foreignId('owner_id'); //->constrained('users');
      $table->string('name',191); // unique dengan owner_id
      $table->string('visibility',16)->default('private');
      $table->softDeletes();
      $table->timestamps();

      $table->unique(['owner_id', 'name']);
    });

    // Manifest
    Schema::create('dochub_manifests', function (Blueprint $table) {
      $table->id();
      $table->foreignId('workspace_id')->nullable();
      $table->foreignId('from_id'); //->constrained('users');
      $table->string('source',255);                // [jenis]:[identifier], 'client:xyz-backend', upload:abcdefg.zip, 
      $table->string('version');               // v1.0.2
      $table->unsignedInteger('total_files');  // "2025-11-30T10:30:00Z",  timestamp otoritatif yang menjadi tulang punggung audit trail, rollback, dan deteksi konflik
      $table->unsignedBigInteger('total_size_bytes'); // file asli, sebelum di hash
      $table->string('hash_tree_sha256', 64); // untuk validasi
      $table->string('storage_path'); // path relatif
      $table->string('tags')->nullable(); // untuk menyimpan catatan atau tag mengenai manifest
      $table->timestamps();
    });

    // Merge
    Schema::create('dochub_merges', function (Blueprint $table) {
      $table->string('id', 36)->primary(); // UUID, gunakan UUID sebagai ID (lebih aman untuk sync/distribusi)
      $table->string('prev_merge_id', 36)->nullable();
      $table->foreignId('workspace_id'); //->constrained('dochub_workspaces');
      $table->string('manifest_hash',64)->nullable(); //->constrained('dochub_manifests');
      $table->string('label',16)->nullable(); // 'v1.2.3'
      $table->text('message')->nullable();
      $table->timestamp('merged_at');
      $table->timestamps();

      // $table->foreign('prev_merge_id')->references('id')->on('dochub_merges')->onDelete('set null');
    });

    // Merge Session
    Schema::create('dochub_merge_sessions', function (Blueprint $table) {
      $table->id();
      $table->foreignId('target_workspace_id'); //->constrained('dochub_workspaces');
      $table->foreignId('initiated_by_user_id'); //->constrained('users');
      // $table->foreignId('result_merge_id')->nullable(); //->constrained('dochub_merges');
      $table->string('result_merge_id',36); //->constrained('dochub_merges');
      $table->string('source_identifier'); // e.g., 'remote:client-abc', 'upload:20251126-abc.zip', 'rollback:user_id:merge_id
      $table->string('source_type')->default('remote'); // 'remote', 'upload', 'manual', 'rollback
      $table->timestamp('started_at');
      $table->timestamp('completed_at')->nullable();
      $table->string('status')->default('pending');  // pending, scanning, conflicts, resolved, applied, failed
      $table->json('metadata')->nullable(); // // info dari third-party: version, timestamp, etc.
      $table->timestamps();
    });

    // Blob
    Schema::create('dochub_blobs', function (Blueprint $table) {
      $table->string('hash', 64)->primary(); // SHA-256 isi file asli (tidak terkompresi!)
      $table->string('mime_type')->nullable(); // 'application/pdf', 'video/mp4'
      $table->boolean('is_binary')->default(true);
      // size bytes
      $table->unsignedBigInteger('original_size_bytes'); // Ukuran asli (sebelum keputusan kompresi)
      $table->unsignedBigInteger('stored_size_bytes'); // Ukuran setelah penyimpanan (bisa = original_size jika tidak dikompres)
      // Apakah file disimpan dalam bentuk terkompresi?
      $table->boolean('is_stored_compressed')->default(false);
      $table->string('compression_type')->nullable(); // 'gzip', 'zstd', null
      $table->boolean('is_already_compressed')->default(false) // deteksi otomatis
        ->comment("True if file is PDF/MP4/JPG/etc — skip compression");
      $table->timestamps();
    });

    // File
    Schema::create('dochub_files', function (Blueprint $table) {
      // $table->id();
      $table->uuid('id')->primary(); // jadi CHAR(36) NOT NULL
      $table->foreignId('workspace_id'); //->constrained('dochub_workspaces');
      $table->string('merge_id', 36); // UUID, state di merge ini
      $table->string('relative_path'); // e.g., 'config/app.php'
      $table->string('blob_hash', 64); // isi file → pointer ke blobs/
      // $table->string('old_blob_hash', 64)->nullable();  // null = added

      // Jika action = 'deleted' → hapus file dari workspace live.
      // Jika action = 'added' → abaikan (karena di M2 belum ada).
      // Jika action = 'updated' → symlink blobs/{$file->blob_hash} ke $path.
      $table->string('action')->default('added'); 
      $table->unsignedBigInteger('size_bytes');
      // $table->timestamp('file_modified_at'); // mtime dari file asli

      // $table->unique(['merge_id', 'relative_path']);
      // $table->unique(['workspace_id', 'relative_path']);
      $table->index(['workspace_id', 'relative_path']);
      $table->index('blob_hash');
      // $table->index('old_blob_hash');
      $table->timestamps();

      // $table->foreign('merge_id')->references('id')->on('dochub_merges')->onDelete('cascade');
      // $table->foreign('blob_hash')->references('hash')->on('dochub_blobs')->onDelete('restrict');
      // $table->foreign('old_blob_hash')->references('hash')->on('dochub_blobs')->onDelete('set null');
    });
  }

  public function down()
  {
    Schema::dropIfExists('dochub_files');
    Schema::dropIfExists('dochub_blobs');
    Schema::dropIfExists('dochub_merge_sessions');
    Schema::dropIfExists('dochub_merges');
    Schema::dropIfExists('dochub_manifests');
    Schema::dropIfExists('dochub_workspaces');
  }
};
