<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('topic')->nullable();
            $table->text('running_summary')->nullable(); // rolling compression
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['system','user','assistant','tool']);
            $table->longText('content');
            $table->json('meta')->nullable(); // tokens, model, cost, etc.
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('difficulty')->default(2); // 1-5
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->enum('priority', ['low','medium','high','urgent'])->default('medium');
            $table->date('due_date')->nullable();
            $table->enum('status', ['New','In Progress','Blocked','Done'])->default('New');
            $table->json('dependencies')->nullable(); // array of task IDs
            $table->timestamps();
            $table->index(['status','priority','due_date']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['brain_dump','pdf','url','structured']);
            $table->longText('content');
            $table->json('meta')->nullable(); // filename, url, mime, etc.
            $table->timestamps();
        });

        // Optional semantic store (works even without pgvector; store JSON embeddings)
        Schema::create('semantic_chunks', function (Blueprint $table) {
            $table->id();
            $table->morphs('referencable'); // note, task, etc.
            $table->longText('chunk');
            $table->json('embedding')->nullable(); // vector as JSON for portability
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['referencable_type','referencable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semantic_chunks');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
