<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->json('alternate_titles')->nullable()->after('title');
            $table->date('publication_date')->nullable()->after('year')->index();
            $table->string('work_format', 100)->nullable()->after('publication_date')->index();
            $table->json('credits')->nullable()->after('artist');
            $table->json('franchises')->nullable()->after('credits');
            $table->json('characters')->nullable()->after('franchises');
            $table->json('source_references')->nullable()->after('characters');
            $table->json('source_metadata')->nullable()->after('source_references');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->string('language', 35)->nullable()->after('scanlation_group')->index();
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropIndex(['language']);
            $table->dropColumn('language');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex(['publication_date']);
            $table->dropIndex(['work_format']);
            $table->dropColumn([
                'alternate_titles',
                'publication_date',
                'work_format',
                'credits',
                'franchises',
                'characters',
                'source_references',
                'source_metadata',
            ]);
        });
    }
};
