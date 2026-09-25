<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_name',
        'stored_path',
        'status',
        'pages',
        'chunk_count',
        'error_message',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
