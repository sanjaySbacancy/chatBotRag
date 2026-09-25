import { useCallback, useEffect, useRef, useState } from 'react';

const NON_TERMINAL = ['uploading', 'processing'];

export function useDocuments() {
    const [documents, setDocuments] = useState([]);
    const [loading, setLoading] = useState(true);
    const timerRef = useRef(null);

    const fetchDocuments = useCallback(async () => {
        const res = await fetch('/api/documents');
        if (!res.ok) return;
        const data = await res.json();
        setDocuments(data);
        setLoading(false);
    }, []);

    useEffect(() => {
        fetchDocuments();
    }, [fetchDocuments]);

    useEffect(() => {
        const hasPending = documents.some((d) => NON_TERMINAL.includes(d.status));
        if (!hasPending) return undefined;

        timerRef.current = setInterval(fetchDocuments, 3000);
        return () => clearInterval(timerRef.current);
    }, [documents, fetchDocuments]);

    const upload = useCallback(async (file) => {
        const optimistic = {
            id: `pending-${Date.now()}`,
            original_name: file.name,
            status: 'uploading',
            pages: null,
            chunk_count: 0,
        };
        setDocuments((prev) => [optimistic, ...prev]);

        const formData = new FormData();
        formData.append('file', file);

        try {
            const res = await fetch('/api/documents', { method: 'POST', body: formData });
            if (!res.ok) throw new Error('Upload failed');
            const created = await res.json();
            setDocuments((prev) => [created, ...prev.filter((d) => d.id !== optimistic.id)]);
        } catch (e) {
            setDocuments((prev) =>
                prev.map((d) => (d.id === optimistic.id ? { ...d, status: 'failed', error_message: 'Upload failed' } : d))
            );
        }
    }, []);

    const remove = useCallback(async (id) => {
        setDocuments((prev) => prev.filter((d) => d.id !== id));
        await fetch(`/api/documents/${id}`, { method: 'DELETE' });
    }, []);

    return { documents, loading, upload, remove, refresh: fetchDocuments };
}
