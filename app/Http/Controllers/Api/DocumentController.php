<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\PineconeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index()
    {
        return Document::orderByDesc('id')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:20480',
        ]);

        $file = $request->file('file');
        $path = $file->store('documents');

        $document = Document::create([
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'status' => 'processing',
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return response()->json($document, 201);
    }

    public function destroy(Document $document, PineconeService $pinecone)
    {
        $pinecone->deleteByDocument($document->id);

        Storage::delete($document->stored_path);
        $document->delete();

        return response()->noContent();
    }
}
