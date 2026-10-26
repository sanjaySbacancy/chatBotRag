<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;

class MessageFeedbackController extends Controller
{
    public function store(Request $request, Message $message)
    {
        abort_unless($message->role === 'assistant', 422, 'Feedback is only accepted on assistant messages.');

        $data = $request->validate([
            'rating' => 'required|in:up,down',
            'reason' => 'nullable|string|max:500',
        ]);

        $feedback = $message->feedback()->updateOrCreate([], $data + ['reason' => null]);

        return response()->json($feedback, 201);
    }
}
