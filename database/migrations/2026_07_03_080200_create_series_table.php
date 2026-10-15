<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('author')->nullable();
            $table->string('artist')->nullable();
            $table->smallInteger('year')->nullable();

            // ongoing | completed | hiatus
            $table->string('status')->default('ongoing');
            // rtl | ltr | vertical
            $table->string('reading_direction')->default('rtl');
            // safe | mature
            $table->string('content_rating')->default('safe');

            $table->text('synopsis')->nullable();
            $table->string('cover_path')->nullable();
            // Tonal placeholder hue used when a series has no cover image.
            $table->unsignedSmallInteger('cover_hue')->default(210);
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
