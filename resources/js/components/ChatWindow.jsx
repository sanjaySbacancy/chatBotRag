import { useEffect, useRef } from 'react';
import MessageBubble from './MessageBubble.jsx';

export default function ChatWindow({ messages }) {
    const bottomRef = useRef(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [messages]);

    if (messages.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center px-6 text-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50">
                    <svg className="h-7 w-7 text-indigo-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                </div>
                <h2 className="mt-4 text-lg font-semibold text-zinc-800">Ask about your documents</h2>
                <p className="mt-1.5 max-w-sm text-sm text-zinc-400">
                    Upload a PDF in the sidebar, wait for it to finish processing, then ask anything about its contents.
                </p>
            </div>
        );
    }

    return (
        <div className="flex-1 space-y-4 overflow-y-auto px-4 py-6 sm:px-8">
            <div className="mx-auto max-w-3xl space-y-4">
                {messages.map((m) => (
                    <MessageBubble key={m.id} message={m} />
                ))}
                <div ref={bottomRef} />
            </div>
        </div>
    );
}
