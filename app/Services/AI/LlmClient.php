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
