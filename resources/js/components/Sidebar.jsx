import DocumentUploadPanel from './DocumentUploadPanel.jsx';

export default function Sidebar({ documents, onUpload, onRemove, open, onClose }) {
    return (
        <>
            {open && (
                <div
                    className="fixed inset-0 z-30 bg-zinc-900/30 backdrop-blur-sm md:hidden"
                    onClick={onClose}
                />
            )}

            <aside
                className={`fixed inset-y-0 left-0 z-40 flex w-80 flex-col border-r border-zinc-200 bg-white p-5 transition-transform duration-200 md:static md:translate-x-0 ${
                    open ? 'translate-x-0' : '-translate-x-full'
                }`}
            >
                <div className="mb-5 flex items-center gap-2.5">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                        </svg>
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-zinc-900">Document Chat</p>
                        <p className="text-xs text-zinc-400">RAG over your PDFs</p>
                    </div>
                </div>

                <DocumentUploadPanel documents={documents} onUpload={onUpload} onRemove={onRemove} />
            </aside>
        </>
    );
}
