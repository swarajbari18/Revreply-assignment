import React from 'react';
import { Message } from '../types';

interface ThreadMessageBubbleProps {
    message: Message;
}

export const ThreadMessageBubble: React.FC<ThreadMessageBubbleProps> = ({ message }) => {
    const isAssistant = message.role === 'assistant';

    const formatDate = (dateStr: string) => {
        try {
            const date = new Date(dateStr);
            return date.toLocaleString([], {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return dateStr;
        }
    };

    return (
        <div className={`flex flex-col mb-4 ${isAssistant ? 'items-end' : 'items-start'}`}>
            {/* Sender Metadata */}
            <div className="flex items-center gap-2 mb-1 px-1 text-xs text-gray-500">
                <span className="font-semibold text-gray-400">
                    {isAssistant ? 'AI Assistant' : message.from.split(' <')[0]}
                </span>
                <span>•</span>
                <span>{formatDate(message.timestamp)}</span>
            </div>

            {/* Bubble Content */}
            <div
                className={`max-w-[85%] rounded-2xl px-4 py-3 shadow-md text-sm leading-relaxed whitespace-pre-wrap ${
                    isAssistant
                        ? 'bg-indigo-600/90 text-white rounded-tr-none border border-indigo-500/30'
                        : 'bg-[#181824] text-gray-200 rounded-tl-none border border-white/5'
                }`}
            >
                {message.body}
            </div>
        </div>
    );
};
