const STYLES = {
    uploading: 'bg-zinc-100 text-zinc-500 border-zinc-200',
    processing: 'bg-amber-50 text-amber-700 border-amber-200',
    ready: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    failed: 'bg-rose-50 text-rose-700 border-rose-200',
};

const LABELS = {
    uploading: 'Uploading',
    processing: 'Processing',
    ready: 'Ready',
    failed: 'Failed',
};

export default function StatusBadge({ status }) {
    const pulsing = status === 'uploading' || status === 'processing';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${STYLES[status] ?? STYLES.uploading}`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${
                    status === 'ready'
                        ? 'bg-emerald-500'
                        : status === 'failed'
                        ? 'bg-rose-500'
                        : 'bg-amber-500'
                } ${pulsing ? 'animate-pulse' : ''}`}
            />
            {LABELS[status] ?? status}
        </span>
    );
}
