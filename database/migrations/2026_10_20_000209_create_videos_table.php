<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('source_url', 2048);
            $table->string('title', 500)->nullable();
            $table->string('author')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('video_url')->nullable();

            $table->string('collection_id')->nullable()->index();
            $table->string('collection_title')->nullable();
            $table->unsignedInteger('episode_number')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('provider')->default('tikwm');

            $table->timestamps();

            $table->unique('source_url', 'videos_source_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
