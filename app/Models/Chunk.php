<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'chunk_index',
        'page_number',
        'content',
        'pinecone_vector_id',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
