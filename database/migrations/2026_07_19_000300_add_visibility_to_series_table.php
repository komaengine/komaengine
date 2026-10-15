<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            // Existing installations remain public after upgrading. The admin
            // create form deliberately saves new series as private instead.
            $table->boolean('is_public')->default(true)->after('content_rating')->index();
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex(['is_public']);
            $table->dropColumn('is_public');
        });
    }
};
