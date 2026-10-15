<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->string('cover_thumbnail_path')->nullable()->after('cover_path');
            $table->unsignedInteger('cover_width')->nullable()->after('cover_thumbnail_path');
            $table->unsignedInteger('cover_height')->nullable()->after('cover_width');
            $table->unsignedBigInteger('cover_file_size')->nullable()->after('cover_height');
            $table->unsignedInteger('cover_thumbnail_width')->nullable()->after('cover_file_size');
            $table->unsignedInteger('cover_thumbnail_height')->nullable()->after('cover_thumbnail_width');
            $table->unsignedBigInteger('cover_thumbnail_file_size')->nullable()->after('cover_thumbnail_height');
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn([
                'cover_thumbnail_path',
                'cover_width',
                'cover_height',
                'cover_file_size',
                'cover_thumbnail_width',
                'cover_thumbnail_height',
                'cover_thumbnail_file_size',
            ]);
        });
    }
};
