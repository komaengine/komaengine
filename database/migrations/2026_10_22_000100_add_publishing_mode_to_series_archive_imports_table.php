<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_archive_imports', function (Blueprint $table) {
            // Null means automatic, which is also how imports queued before
            // this column existed are treated.
            $table->string('publishing_mode', 32)->nullable()->after('replace_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('series_archive_imports', function (Blueprint $table) {
            $table->dropColumn('publishing_mode');
        });
    }
};
