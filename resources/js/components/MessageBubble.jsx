import ProcessSteps from './ProcessSteps.jsx';
import SourcesAccordion from './SourcesAccordion.jsx';

export default function MessageBubble({ message }) {
    const isUser = message.role === 'user';
    const hasSteps = !isUser && message.steps?.length > 0;
    const showAnswer = message.content !== '' || !message.streaming;

    return (
        <div className={`flex ${isUser ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[85%] rounded-2xl px-4 py-3 shadow-sm sm:max-w-[70%] ${
                    isUser
                        ? 'bg-indigo-600 text-white'
                        : 'border border-zinc-200 bg-white text-zinc-800'
                } ${hasSteps ? 'sm:max-w-[80%]' : ''}`}
            >
                {hasSteps && <ProcessSteps steps={message.steps} />}

                {showAnswer && (
                    <p className="whitespace-pre-wrap text-sm leading-relaxed">
                        {message.content}
                        {message.streaming && message.content !== '' && (
                            <span className="ml-0.5 inline-block h-4 w-1.5 translate-y-0.5 animate-pulse bg-current align-middle" />
                        )}
                    </p>
                )}

                {!isUser && !message.streaming && <SourcesAccordion sources={message.sources} />}
            </div>
        </div>
    );
}
