import { useRef, useState } from 'react';

export default function ChatComposer({ onSend, disabled }) {
    const [value, setValue] = useState('');
    const textareaRef = useRef(null);

    const submit = () => {
        const text = value.trim();
        if (!text || disabled) return;
        onSend(text);
        setValue('');
        if (textareaRef.current) textareaRef.current.style.height = 'auto';
    };

    return (
        <div className="border-t border-zinc-200 bg-white p-4">
            <div className="mx-auto flex max-w-3xl items-end gap-2 rounded-2xl border border-zinc-200 bg-zinc-50 p-2 shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100">
                <textarea
                    ref={textareaRef}
                    value={value}
                    onChange={(e) => {
                        setValue(e.target.value);
                        e.target.style.height = 'auto';
                        e.target.style.height = `${Math.min(e.target.scrollHeight, 160)}px`;
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            submit();
                        }
                    }}
                    rows={1}
                    placeholder="Ask a question about your documents..."
                    disabled={disabled}
                    className="max-h-40 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm text-zinc-800 placeholder:text-zinc-400 focus:outline-none disabled:opacity-60"
                />
                <button
                    onClick={submit}
                    disabled={disabled || !value.trim()}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white transition-colors hover:bg-indigo-500 disabled:bg-zinc-200 disabled:text-zinc-400"
                    aria-label="Send message"
                >
                    <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z" />
                    </svg>
                </button>
            </div>
            <p className="mx-auto mt-2 max-w-3xl text-center text-[11px] text-zinc-400">
                Answers are generated only from your uploaded documents.
            </p>
        </div>
    );
}
