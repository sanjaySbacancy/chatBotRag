import { useState } from 'react';
import Sidebar from './components/Sidebar.jsx';
import ChatWindow from './components/ChatWindow.jsx';
import ChatComposer from './components/ChatComposer.jsx';
import { useDocuments } from './hooks/useDocuments.js';
import { useChatStream } from './hooks/useChatStream.js';

export default function App() {
    const { documents, upload, remove } = useDocuments();
    const { send, streaming } = useChatStream();

    const [messages, setMessages] = useState([]);
    const [conversationId, setConversationId] = useState(null);
    const [sidebarOpen, setSidebarOpen] = useState(false);

    const hasReadyDoc = documents.some((d) => d.status === 'ready');

    const handleSend = (text) => {
        const userMessage = { id: `u-${Date.now()}`, role: 'user', content: text };
        const assistantId = `a-${Date.now()}`;

        setMessages((prev) => [
            ...prev,
            userMessage,
            { id: assistantId, role: 'assistant', content: '', sources: [], steps: [], streaming: true },
        ]);

        send({
            message: text,
            conversationId,
            onMeta: (payload) => setConversationId(payload.conversation_id),
            onStep: (step) => {
                setMessages((prev) =>
                    prev.map((m) => {
                        if (m.id !== assistantId) return m;
                        const idx = m.steps.findIndex((s) => s.id === step.id);
                        const steps =
                            idx === -1
                                ? [...m.steps, step]
                                : m.steps.map((s, i) => (i === idx ? step : s));
                        return { ...m, steps };
                    })
                );
            },
            onToken: (token) => {
                setMessages((prev) =>
                    prev.map((m) => (m.id === assistantId ? { ...m, content: m.content + token } : m))
                );
            },
            onDone: (payload) => {
                setMessages((prev) =>
                    prev.map((m) =>
                        m.id === assistantId ? { ...m, sources: payload.sources ?? [], streaming: false } : m
                    )
                );
            },
            onStreamError: (payload) => {
                setMessages((prev) =>
                    prev.map((m) =>
                        m.id === assistantId ? { ...m, content: payload.message, streaming: false } : m
                    )
                );
            },
            onError: () => {
                setMessages((prev) =>
                    prev.map((m) =>
                        m.id === assistantId
                            ? { ...m, content: m.content || 'Something went wrong. Please try again.', streaming: false }
                            : m
                    )
                );
            },
        });
    };

    return (
        <div className="flex h-full bg-zinc-50">
            <Sidebar
                documents={documents}
                onUpload={upload}
                onRemove={remove}
                open={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
            />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-3 border-b border-zinc-200 bg-white px-4 py-3 md:hidden">
                    <button
                        onClick={() => setSidebarOpen(true)}
                        className="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100"
                        aria-label="Open documents"
                    >
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                        </svg>
                    </button>
                    <p className="text-sm font-semibold text-zinc-900">Document Chat</p>
                </header>

                <ChatWindow messages={messages} />

                {!hasReadyDoc && messages.length === 0 && (
                    <p className="mx-auto -mt-2 mb-2 text-center text-xs text-amber-600">
                        Upload and wait for at least one document to finish processing before asking questions.
                    </p>
                )}

                <ChatComposer onSend={handleSend} disabled={streaming} />
            </div>
        </div>
    );
}
