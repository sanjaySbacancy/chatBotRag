<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;

class ConversationController extends Controller
{
    public function index()
    {
        return ChatConversation::orderByDesc('id')->get();
    }

    public function show(ChatConversation $conversation)
    {
        return $conversation->load('messages');
    }
}
