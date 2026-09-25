import { useRef, useState } from 'react';
import StatusBadge from './StatusBadge.jsx';

export default function DocumentUploadPanel({ documents, onUpload, onRemove }) {
    const inputRef = useRef(null);
    const [dragOver, setDragOver] = useState(false);

    const handleFiles = (files) => {
        [...files].filter((f) => f.type === 'application/pdf').forEach(onUpload);
    };

    return (
        <div className="flex flex-1 flex-col gap-4 overflow-hidden">
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragOver(false);
                    handleFiles(e.dataTransfer.files);
                }}
                onClick={() => inputRef.current?.click()}
                className={`cursor-pointer rounded-2xl border-2 border-dashed p-5 text-center transition-colors ${
                    dragOver ? 'border-indigo-400 bg-indigo-50' : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300'
                }`}
            >
                <svg className="mx-auto h-6 w-6 text-zinc-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                <p className="mt-2 text-sm font-medium text-zinc-700">Drop a PDF or click to upload</p>
                <p className="mt-0.5 text-xs text-zinc-400">Indexed for retrieval automatically</p>
                <input
                    ref={inputRef}
                    type="file"
                    accept="application/pdf"
                    multiple
                    className="hidden"
                    onChange={(e) => {
                        handleFiles(e.target.files);
                        e.target.value = '';
                    }}
                />
            </div>

            <div className="flex-1 overflow-y-auto">
                {documents.length === 0 ? (
                    <p className="px-1 text-sm text-zinc-400">No documents yet.</p>
                ) : (
                    <ul className="space-y-2">
                        {documents.map((doc) => (
                            <li
                                key={doc.id}
                                className="group rounded-xl border border-zinc-200 bg-white p-3 shadow-sm"
                                title={doc.status === 'failed' ? doc.error_message : undefined}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <p className="min-w-0 flex-1 truncate text-sm font-medium text-zinc-800">
                                        {doc.original_name}
                                    </p>
                                    <button
                                        onClick={() => onRemove(doc.id)}
                                        className="shrink-0 rounded-md p-1 text-zinc-300 opacity-0 transition-opacity hover:bg-zinc-100 hover:text-rose-500 group-hover:opacity-100"
                                        aria-label="Remove document"
                                    >
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                <div className="mt-2 flex items-center justify-between">
                                    <StatusBadge status={doc.status} />
                                    {doc.status === 'ready' && (
                                        <span className="text-xs text-zinc-400">
                                            {doc.pages} pg &middot; {doc.chunk_count} chunks
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
