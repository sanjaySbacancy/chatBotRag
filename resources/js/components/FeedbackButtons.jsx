import { useState } from 'react';

export default function FeedbackButtons({ messageId }) {
    const [rating, setRating] = useState(null);
    const [failed, setFailed] = useState(false);

    if (!messageId) return null;

    const submit = async (value) => {
        setFailed(false);
        const previous = rating;
        setRating(value);

        try {
            const res = await fetch(`/api/messages/${messageId}/feedback`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ rating: value }),
            });
            if (!res.ok) throw new Error('feedback failed');
        } catch {
            setRating(previous);
            setFailed(true);
        }
    };

    const button = (value, label, path) => (
        <button
            onClick={() => submit(value)}
            aria-label={label}
            aria-pressed={rating === value}
            className={`rounded p-1 hover:bg-zinc-100 ${rating === value ? 'text-indigo-600' : 'text-zinc-400'}`}
        >
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.8} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d={path} />
            </svg>
        </button>
    );

    return (
        <div className="mt-2 flex items-center gap-1">
            {button('up', 'Good answer', 'M6.633 10.5c.806 0 1.533-.446 2.031-1.08a9.04 9.04 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.5 4.5 0 0 0 .322-1.672V3a.75.75 0 0 1 .75-.75A2.25 2.25 0 0 1 16.5 4.5c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.5 4.5 0 0 0-1.423-.23H5.904')}
            {button('down', 'Bad answer', 'M7.5 15h2.25m8.024-9.75c.011.05.028.1.052.148.591 1.2.924 2.55.924 3.977a8.96 8.96 0 0 1-.999 4.125m.023-8.25c-.076-.365.183-.75.575-.75h.908c.889 0 1.713.518 1.972 1.368.339 1.11.521 2.287.521 3.507 0 1.553-.295 3.036-.831 4.398-.306.774-1.086 1.227-1.918 1.227h-1.053c-.472 0-.745-.556-.5-.96a8.95 8.95 0 0 0 .303-.54m.023-8.25H16.48a4.5 4.5 0 0 1-1.423-.23l-3.114-1.04a4.5 4.5 0 0 0-1.423-.23H6.504c-.618 0-1.217.247-1.605.729A11.95 11.95 0 0 0 2.25 12c0 .434.023.863.068 1.285C2.427 14.306 3.346 15 4.372 15h3.126c.618 0 .991.724.725 1.282A7.47 7.47 0 0 0 7.5 19.5a2.25 2.25 0 0 0 2.25 2.25.75.75 0 0 0 .75-.75v-.633c0-.573.11-1.14.322-1.672.304-.76.93-1.33 1.653-1.715a9.04 9.04 0 0 0 2.86-2.4c.498-.634 1.226-1.08 2.032-1.08h.384')}
            {failed && <span className="text-[10px] text-red-500">Could not save</span>}
        </div>
    );
}
