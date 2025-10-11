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
        Schema::create('lastfm_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained('lastfm_artists')->onDelete('cascade');
            $table->string('track_name');
            $table->text('lastfm_url')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('seen')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lastfm_tracks');
    }
};
