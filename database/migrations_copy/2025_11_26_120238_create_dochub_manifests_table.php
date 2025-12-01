<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// {
//   "source": "client-xyz-backend",
//   "version": "2025-11-26T10:30:00Z",
//   "total_files": 128,
//   "total_size_bytes": 4567890,
//   "hash_tree_sha256": "a1b2c3...", // hash dari seluruh daftar file → validasi integritas
//   "files": [
//     {
//       "path": "config/app.php",
//       "size": 2314,
//       "mtime": "2025-11-26T09:15:22Z",
//       "sha256": "d4e5f6...",
//       "is_binary": false
//     },
//     {
//       "path": "public/logo.png",
//       "size": 15420,
//       "mtime": "2025-11-20T14:00:00Z",
//       "sha256": "7a8b9c...",
//       "is_binary": true
//     }
//   ]
// }

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('dochub_manifests', function (Blueprint $table) {
      $table->id();
      // $table->foreignId('workspace_id')->constrained();
      $table->foreignId('workspace_id');
      $table->foreignId('from_id')->constrained('users');

      // 🔑 Metadata kritis di DB (untuk query cepat)
      $table->string('source');                // 'client-xyz-backend'
      $table->string('version');               // '2025-11-26T10:30:00Z'
      $table->unsignedInteger('total_files');
      $table->unsignedBigInteger('total_size_bytes');
      $table->string('hash_tree_sha256', 64); // untuk validasi

      // 🔑 Pointer ke file fisik
      $table->string('storage_path');         // 'manifests/2025/11/abc123.json', relative to app/private/dochub/workspace

      // Index untuk query cepat
      $table->index('source');
      $table->index('version');
      $table->index('created_at');

      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('dochub_manifests');
  }
};
