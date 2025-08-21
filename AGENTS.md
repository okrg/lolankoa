# AGENTS.md

## Purpose

The agentic coding assistant will implement a production-ready, AI-powered task management and context-aware conversation system within a Laravel application. The core objective is to create a system that can parse natural language input, generate structured tasks, and maintain conversational memory using a dynamic context window. The system must be compatible with both OpenAI and other OpenAI-compatible APIs like Ollama or Groq.

-----

## Scope & Limitations

  * **In Scope:**
      * Generate all specified PHP classes, database migrations, configuration files, and routes as defined in this document.
      * Implement the full data model including `Conversation`, `Message`, `Task`, `Note`, and `SemanticChunk` tables.
      * Create the core logic for the `ContextBuilder` service, the `LlmClient` wrapper, and the `ParseInputAction` orchestrator.
      * Set up the `IngestController` to expose the functionality via an API endpoint.
  * **Out of Scope (Anti-Patterns):**
      * **DO NOT** run any long-running terminal commands such as `composer install`, `composer require`, `npm install`, or `php artisan migrate`. Assume all dependencies are installed and migrations will be run manually by the developer.
      * **DO NOT** invent or fabricate solutions. Adhere strictly to the architecture and code provided.
      * **DO NOT** implement features beyond the specification, such as the optional "upgrades" (PDF parsing, Livewire UI, etc.) unless specifically requested in a follow-up task.
      * **DO NOT** modify the `.env` file directly. The agent should only specify which variables are needed.

-----

## Reasoning, Planning & Workflow

Before writing any code, the agent must outline its plan. The high-level workflow should follow this checklist:

1.  **Project Structure:** Review the target file structure to understand the final layout.
2.  **Configuration:** Set up the necessary service configuration and environment variable definitions.
3.  **Database Schema:** Create the database migration file to define all required tables.
4.  **Eloquent Models:** Create the corresponding Eloquent models for each table.
5.  **Core Services:** Implement the `LlmClient` and the critical `ContextBuilder` service.
6.  **Orchestration Logic:** Implement the `ParseInputAction` to tie the services together.
7.  **API Layer:** Create the `IngestController` and define the API route to expose the system.

-----

## Operational Steps

This project should be implemented in the order below. After creating each file, validate its contents against the provided specification before proceeding.

### 1\. Project Setup (Developer Task)

This step is for the developer. **Agent, you will skip this and assume it is complete.**

  * A new Laravel project is installed.
  * The following PHP package is required. The developer will run:
    ```bash
    composer require openai-php/laravel
    ```
  * The database is configured (Postgres is recommended).

### 2\. Configuration

First, create the configuration for the OpenAI client.

#### File: `config/services.php`

Add the following `openai` key to the array in this file.

```php
return [
    // ... other services

    'openai' => [
        'api_key'  => env('OPENAI_API_KEY'),
        'base_uri' => env('OPENAI_BASE_URI', 'https://api.openai.com/v1'), // For OpenAI-compatible servers
        'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],
];
```

#### File: `.env.example`

Add these lines to your `.env.example` file so the developer knows what environment variables to set.

```env
OPENAI_API_KEY=your-api-key
OPENAI_BASE_URI=https://api.openai.com/v1
OPENAI_MODEL=gpt-4o-mini

# Example for a local LLM like Ollama
# OPENAI_API_KEY=ollama
# OPENAI_BASE_URI=http://localhost:11434/v1
# OPENAI_MODEL=llama3.1:8b-instruct
```

-----

### 3\. Database Schema

Create a single migration file for the core AI tables.

#### File: `database/migrations/YYYY_MM_DD_HHMMSS_create_ai_core_tables.php`

Use the `php artisan make:migration create_ai_core_tables` command name suggestion.

```php
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
```

-----

### 4\. Eloquent Models

Now, create the Eloquent models for the tables defined above. These will be simple models without relationships defined for now, to keep the task focused.

| File Path | Model Class |
| --- | --- |
| `app/Models/Conversation.php` | `Conversation` |
| `app/Models/Message.php` | `Message` |
| `app/Models/Task.php` | `Task` |
| `app/Models/Note.php` | `Note` |
| `app/Models/SemanticChunk.php`| `SemanticChunk`|

Create each file with the following minimal content, adjusting the class name for each one.

**Example for `app/Models/Conversation.php`:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
```

**Apply a similar structure for the other models (`Message`, `Task`, `Note`, `SemanticChunk`), ensuring the `HasFactory` trait and an empty `$guarded` property are included.** For `Task`, also cast the `dependencies` attribute to an array: `protected $casts = ['dependencies' => 'array'];`.

-----

### 5\. Core Services

Create the services that will handle AI interactions and context management.

#### File: `app/Services/AI/LlmClient.php`

This is a thin wrapper around the OpenAI client.

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use OpenAI;
use Illuminate\Support\Arr;

final class LlmClient
{
    public function __construct(
        private readonly string $model = '',
    ) {}

    public function chat(array $messages, array $options = []): array
    {
        $client = OpenAI::factory()
            ->withBaseUri(config('services.openai.base_uri'))
            ->withApiKey(config('services.openai.api_key'))
            ->make();

        $res = $client->chat()->create(array_filter([
            'model'       => $options['model'] ?? config('services.openai.model'),
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens'  => $options['max_tokens'] ?? 800,
        ]));

        return [
            'content' => Arr::get($res->choices, '0.message.content', ''),
            'raw'     => $res->toArray(),
        ];
    }
}
```

#### File: `app/Services/AI/ContextBuilder.php`

This is the most critical class for managing the LLM's context window.

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\{Conversation, Task};
use Illuminate\Support\Str;

final class ContextBuilder
{
    /** ~safe character budgets; swap with token-based later */
    private int $budgetChars = 12000;           // total input budget
    private int $recentTailChars = 6000;        // recent turns
    private int $taskSnapshotChars = 4000;      // task state
    private int $semanticRecallChars = 2000;    // related chunks

    public function buildForParsing(Conversation $conv): array
    {
        $system = $this->systemPrompt();

        $summary = $conv->running_summary ?? '';
        $recent  = $this->recentTail($conv, $this->recentTailChars);
        $tasks   = $this->taskSnapshot($this->taskSnapshotChars);
        $recall  = $this->semanticRecall($conv, $this->semanticRecallChars);

        // Trim if needed to stay under global budget (coarse)
        $payload = trim($summary . "\n\n" . $tasks . "\n\n" . $recall . "\n\n" . $recent);
        if (Str::length($payload) > $this->budgetChars) {
            $payload = Str::limit($payload, $this->budgetChars);
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'system', 'content' => "CONTEXT:\n" . $payload],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<TXT
You are the ATR (AI Task Refiner). Parse incoming inputs into:
- actionable tasks (atomic ~30min units) with: title, description, difficulty 1–5, ideal_duration (minutes), dependencies (ids or text), priority (low|medium|high|urgent), suggested due date (YYYY-MM-DD), status="New".
- references (non-actionable).
Respect existing task history. Never duplicate tasks: match by semantic similarity and merge when appropriate. Prefer clarity over verbosity.
Return JSON with shape: { "tasks": [...], "references": [...], "links": [...], "notes": [...] }.
TXT;
    }

    private function recentTail(Conversation $conv, int $limitChars): string
    {
        $messages = $conv->messages()->latest()->take(30)->get()->reverse();
        $buf = '';
        foreach ($messages as $m) {
            $line = strtoupper($m->role) . ': ' . trim($m->content) . "\n";
            if (Str::length($buf) + Str::length($line) > $limitChars) break;
            $buf .= $line;
        }
        return "RECENT:\n" . $buf;
    }

    private function taskSnapshot(int $limitChars): string
    {
        $q = Task::query()
            ->select(['id','title','status','priority','due_date','duration_minutes'])
            ->latest('updated_at')
            ->limit(300)
            ->get();

        $lines = $q->map(fn($t) =>
            sprintf("#%d [%s/%s] %s (due:%s, %dmin)",
                $t->id, $t->status, $t->priority, $t->title, $t->due_date?->format('Y-m-d') ?? '-', $t->duration_minutes
            )
        )->implode("\n");

        return "TASKS_SNAPSHOT:\n" . Str::limit($lines, $limitChars);
    }

    private function semanticRecall(Conversation $conv, int $limitChars): string
    {
        // Stub: in MVP return empty; later: top-k chunks from semantic_chunks by embedding similarity.
        return "SEMANTIC_RECALL:\n";
    }
}
```

-----

### 6\. Orchestration Action

This action class coordinates the process of receiving input, building context, calling the LLM, and persisting the results.

#### File: `app/Actions/ParseInputAction.php`

````php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\{Conversation, Message, Note, Task};
use App\Services\AI\{LlmClient, ContextBuilder};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ParseInputAction
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ContextBuilder $context,
    ) {}

    /**
     * @param  array{ conversation: Conversation, text: string, attachments?: array<int, array{content:string, meta?:array}> } $payload
     */
    public function __invoke(array $payload): array
    {
        $conv = $payload['conversation'];

        // 1) Persist raw user note + message
        Note::create([
            'conversation_id' => $conv->id,
            'type' => 'brain_dump',
            'content' => $payload['text'],
        ]);

        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $payload['text'],
        ]);

        // 2) Build dynamic context
        $ctx = $this->context->buildForParsing($conv);

        // 3) Ask the model
        $messages = array_merge($ctx, [
            ['role' => 'user', 'content' => "INPUT:\n" . $payload['text']],
        ]);

        $res = $this->llm->chat($messages, [
            'temperature' => 0.1,
            'max_tokens'  => 1400,
        ]);

        // 4) Persist the assistant message
        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => $res['content'],
            'meta' => ['raw' => $res['raw']],
        ]);

        // 5) Parse JSON safely and upsert tasks
        $data = $this->safeJson($res['content']);

        DB::transaction(function () use ($data) {
            foreach (Arr::get($data, 'tasks', []) as $t) {
                $existing = Task::query()
                    ->where('title', $t['title'] ?? '')
                    ->when(!empty($t['suggested_due_date']), fn($q) =>
                        $q->whereDate('due_date', $t['suggested_due_date'])
                    )->first();

                $attrs = [
                    'title' => $t['title'] ?? 'Untitled',
                    'description' => $t['description'] ?? null,
                    'difficulty' => (int)($t['difficulty'] ?? 2),
                    'duration_minutes' => (int)($t['ideal_duration'] ?? 30),
                    'priority' => $t['priority'] ?? 'medium',
                    'due_date' => $t['suggested_due_date'] ?? null,
                    'status' => 'New',
                    'dependencies' => $t['dependencies'] ?? [],
                ];

                $existing ? $existing->update($attrs) : Task::create($attrs);
            }
        });

        // 6) Update rolling summary (compression step)
        $this->updateSummary($conv);

        return $data;
    }

    private function safeJson(string $content): array
    {
        $json = trim($content);
        $json = preg_replace('/^```json|```$/m', '', $json);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['tasks' => [], 'references' => []];
    }

    private function updateSummary(Conversation $conv): void
    {
        $messages = $conv->messages()->latest()->take(20)->get()->reverse()
            ->map(fn($m) => strtoupper($m->role).': '.$m->content)->implode("\n");

        $summary = str($conv->running_summary."\n".$messages)->limit(6000, '')->__toString();

        $conv->update(['running_summary' => $summary]);
    }
}
````

-----

### 7\. API Layer

Finally, create the controller and route to handle incoming HTTP requests.

#### File: `app/Http/Controllers/IngestController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ParseInputAction;
use App\Models\Conversation;
use Illuminate\Http\Request;

final class IngestController
{
    public function __invoke(Request $request, ParseInputAction $parse)
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable','integer','exists:conversations,id'],
            'text' => ['required','string'],
            'files.*' => ['file','mimes:txt,pdf'],
        ]);

        $conv = $validated['conversation_id']
            ? Conversation::find($validated['conversation_id'])
            : Conversation::create();

        // TODO: A future task could parse PDFs/text to Note records here (queueable)
        $result = $parse([
            'conversation' => $conv,
            'text' => $validated['text'],
        ]);

        return response()->json([
            'conversation_id' => $conv->id,
            'result' => $result,
        ]);
    }
}
```

#### File: `routes/api.php`

Add the following route to this file.

```php
use App\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

// ... other api routes

Route::post('/ingest', IngestController::class);

```

-----

## Stopping Criteria & Escalation

  * **Stopping Criteria:** The task is complete once all the files listed above have been created with the correct content and in the correct directory paths.
  * **Escalation:** If you encounter an inability to create a file or a logical conflict in the provided instructions, stop and report the specific issue for clarification.

## Best Practices

  * **Adherence:** Strictly follow the provided code. Do not introduce new public methods or change method signatures.
  * **Validation:** After each file is created, mentally (or through internal reflection) confirm it matches the specification before moving to the next step.
  * **Directory Structure:** Ensure all files are placed in the correct directories (e.g., `app/Services/AI`, `app/Actions`, `app/Http/Controllers`). Create directories if they do not exist.
