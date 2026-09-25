import { useState } from 'react';

export default function SourcesAccordion({ sources }) {
    const [open, setOpen] = useState(false);

    if (!sources || sources.length === 0) return null;

    return (
        <div className="mt-3 border-t border-zinc-100 pt-2">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex items-center gap-1.5 text-xs font-medium text-zinc-500 hover:text-indigo-600"
            >
                <svg
                    className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-90' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
                Sources ({sources.length})
            </button>

            {open && (
                <ul className="mt-2 space-y-2">
                    {sources.map((s, i) => (
                        <li key={i} className="rounded-lg bg-zinc-50 p-2.5 text-xs">
                            <div className="mb-1 flex items-center justify-between gap-2">
                                <span className="truncate font-medium text-zinc-700">{s.document_name}</span>
                                {s.page != null && (
                                    <span className="shrink-0 rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-medium text-zinc-600">
                                        p. {s.page}
                                    </span>
                                )}
                            </div>
                            <p className="text-zinc-500">{s.snippet}</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
