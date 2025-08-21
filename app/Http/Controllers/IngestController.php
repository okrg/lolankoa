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
