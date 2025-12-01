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
    Schema::create('dochub_blob_references', function (Blueprint $table) {
      $table->string('hash', 64)->primary(); // SHA-256 isi file asli (tidak terkompresi!)

      $table->string('mime_type')->nullable(); // 'application/pdf', 'video/mp4'
      $table->boolean('is_binary')->default(true);

      // Ukuran asli (sebelum keputusan kompresi)
      $table->unsignedBigInteger('original_size_bytes');

      // Ukuran setelah penyimpanan (bisa = original_size jika tidak dikompres)
      $table->unsignedBigInteger('stored_size_bytes');

      // Apakah file disimpan dalam bentuk terkompresi?
      $table->boolean('is_stored_compressed')->default(false);
      $table->string('compression_type')->nullable(); // 'gzip', 'zstd', null

      // Tambahan untuk file besar:
      $table->boolean('is_already_compressed')->default(false) // deteksi otomatis
        ->comment("True if file is PDF/MP4/JPG/etc — skip compression");

      $table->timestamps();
    });
  }
  // $table->morphs('referenceable');          // fi, snapshot_file, dll
  // path akan menjadi identitas setiap file.
  // setiap file yang pathnya sama akan di multi repository akan memiliki manifest path ini
  // ketika path ini diakses maka akan mengambil blob
  // $table->index(['referenceable_type', 'referenceable_id']);

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('dochub_dochub_blob_references');
  }
};
