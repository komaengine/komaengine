<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_archive_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_name');
            $table->string('source_path')->nullable();
            $table->string('status')->default('queued')->index();
            $table->string('conflict_policy')->default('fail');
            $table->unsignedBigInteger('replacement_series_id')->nullable();
            $table->boolean('replace_confirmed')->default(false);
            $table->foreignId('result_series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage')->default('Queued');
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->json('counts')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_archive_imports');
    }
};
