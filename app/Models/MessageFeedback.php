<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageFeedback extends Model
{
    use HasFactory;

    protected $table = 'message_feedback';

    protected $fillable = ['message_id', 'rating', 'reason'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
