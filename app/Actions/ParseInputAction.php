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
