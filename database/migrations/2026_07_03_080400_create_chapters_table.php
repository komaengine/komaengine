<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            // Decimal so half-chapters (e.g. 10.5) are supported.
            $table->decimal('number', 8, 2);
            $table->string('title')->nullable();
            $table->string('scanlation_group')->nullable();
            // draft | published
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamps();

            $table->index(['series_id', 'number']);
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
