<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('chapters')
            ->select(['series_id', 'number'])
            ->groupBy('series_id', 'number')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new \RuntimeException(
                "Cannot enforce chapter uniqueness: series {$duplicate->series_id} has duplicate chapter {$duplicate->number}."
            );
        }

        Schema::table('chapters', function (Blueprint $table) {
            $table->unique(['series_id', 'number']);
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->dropIndex(['series_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->index(['series_id', 'number']);
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique(['series_id', 'number']);
        });
    }
};
