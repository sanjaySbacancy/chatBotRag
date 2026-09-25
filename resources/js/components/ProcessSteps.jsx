import { useState } from 'react';
import StepIcon from './StepIcon.jsx';

export default function ProcessSteps({ steps }) {
    const [open, setOpen] = useState(true);

    if (!steps || steps.length === 0) return null;

    const completedCount = steps.filter((s) => s.status === 'completed').length;
    const hasError = steps.some((s) => s.status === 'error');
    const inProgress = steps.some((s) => s.status === 'in_progress');

    const label = hasError
        ? `Stopped after ${completedCount} step${completedCount === 1 ? '' : 's'}`
        : inProgress
        ? `Working — ${completedCount} step${completedCount === 1 ? '' : 's'} completed`
        : `Completed ${completedCount} step${completedCount === 1 ? '' : 's'}`;

    return (
        <div className="mb-3 rounded-xl border border-zinc-100 bg-zinc-50/70">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-xs font-semibold text-zinc-600 hover:text-indigo-600"
            >
                <span className="flex items-center gap-1.5">
                    {inProgress && (
                        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-indigo-500" />
                    )}
                    {label}
                </span>
                <svg
                    className={`h-3.5 w-3.5 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {open && (
                <ol className="px-3 pb-3">
                    {steps.map((step, i) => (
                        <li key={step.id} className="relative flex gap-3 pb-3 last:pb-0">
                            {i < steps.length - 1 && (
                                <span className="absolute left-[11px] top-[22px] bottom-0 w-px bg-zinc-200" />
                            )}
                            <StepIcon id={step.id} status={step.status} />
                            <div className="min-w-0 flex-1 pt-0.5">
                                <p
                                    className={`text-xs font-semibold ${
                                        step.status === 'error' ? 'text-rose-600' : 'text-zinc-700'
                                    }`}
                                >
                                    {step.title}
                                </p>
                                {step.detail && (
                                    <p className="mt-0.5 whitespace-pre-line text-xs leading-relaxed text-zinc-500">
                                        {step.detail}
                                    </p>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
