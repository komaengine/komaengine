<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->string('default_reader_mode')->nullable()->after('reading_direction');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('reader_mode')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn('default_reader_mode');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reader_mode');
        });
    }
};
