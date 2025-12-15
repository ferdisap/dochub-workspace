<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up()
  {
    // sama seperti oatuh_token table milik csdb
    Schema::create('dochub_token', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->index();   // token milik user mana (jika applicable)
      // $table->string('provider')->default('passport');     // jika nanti multi-oauth provider, provider → fleksibel jika nanti support Google / GitHub / SSO lain
      $table->string('provider');    // jika nanti multi-oauth provider, provider → fleksibel jika nanti support Google / GitHub / SSO lain
      $table->mediumText('access_token');     // <– FIX // hashed
      $table->mediumText('refresh_token')->nullable(); // <– FIX // hashed
      // $table->string('token_hash', 128);    // <– FIX
      // kegedean karena batasannya varchar 65535 bytes
      // $table->string('access_token', 8192);                 // encrypted string, 8192 panjang aman karena encrypted token bisa jadi panjang
      // $table->string('refresh_token', 8192)->nullable();    // encrypted string
      $table->timestamp('expires_at')->nullable();          // dihitung dari expires_in
      $table->boolean('revoked')->default(false);
      $table->timestamps();
    });

    Schema::create('dochub_saved_token', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->index();   // token milik user mana (jika applicable)
      $table->string('provider');     // jika nanti multi-oauth provider, provider → fleksibel jika nanti support Google / GitHub / SSO lain
      $table->mediumText('access_token');     // <– FIX // hashed
      $table->mediumText('refresh_token')->nullable(); // <– FIX // hashed
      $table->timestamp('expires_at')->nullable();          // dihitung dari expires_in
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('dochub_token');
    Schema::dropIfExists('dochub_saved_token');
  }
};
