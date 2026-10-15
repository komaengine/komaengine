<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->text('details')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('moderation_note')->nullable();
            $table->foreignId('comment_author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment_author_name');
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->text('comment_body');
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
            $table->index(['status', 'created_at']);
            $table->index(['commentable_type', 'commentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reports');
    }
};
