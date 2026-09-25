import { useCallback, useRef, useState } from 'react';

export function useChatStream() {
    const [streaming, setStreaming] = useState(false);
    const controllerRef = useRef(null);

    const send = useCallback(async ({ message, conversationId, onMeta, onToken, onDone, onError, onStreamError, onStep }) => {
        setStreaming(true);
        const controller = new AbortController();
        controllerRef.current = controller;

        const processEvent = (raw) => {
            let event = 'message';
            let dataLine = '';

            raw.split('\n').forEach((line) => {
                if (line.startsWith('event:')) event = line.slice(6).trim();
                else if (line.startsWith('data:')) dataLine += line.slice(5).trim();
            });

            if (!dataLine) return;
            const payload = JSON.parse(dataLine);

            if (event === 'meta') onMeta?.(payload);
            if (event === 'step') onStep?.(payload);
            if (event === 'token') onToken?.(payload.token);
            if (event === 'done') onDone?.(payload);
            if (event === 'error') onStreamError?.(payload);
        };

        try {
            const res = await fetch('/api/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
                body: JSON.stringify({ message, conversation_id: conversationId ?? null }),
                signal: controller.signal,
            });

            if (!res.ok || !res.body) {
                throw new Error(`Chat request failed (${res.status})`);
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                let idx;
                while ((idx = buffer.indexOf('\n\n')) !== -1) {
                    processEvent(buffer.slice(0, idx));
                    buffer = buffer.slice(idx + 2);
                }
            }
        } catch (e) {
            if (e.name !== 'AbortError') onError?.(e);
        } finally {
            setStreaming(false);
        }
    }, []);

    const stop = useCallback(() => {
        controllerRef.current?.abort();
    }, []);

    return { send, streaming, stop };
}
