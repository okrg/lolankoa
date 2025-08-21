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
