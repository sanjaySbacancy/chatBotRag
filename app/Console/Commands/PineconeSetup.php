<?php

namespace App\Console\Commands;

use App\Services\PineconeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pinecone:setup')]
#[Description('Create the Pinecone serverless index used to store document embeddings, if it does not already exist.')]
class PineconeSetup extends Command
{
    public function handle(PineconeService $pinecone): int
    {
        $this->info('Ensuring Pinecone index exists (this can take up to a minute on first create)...');

        $pinecone->ensureIndex();

        $this->info('Pinecone index is ready.');

        return self::SUCCESS;
    }
}
