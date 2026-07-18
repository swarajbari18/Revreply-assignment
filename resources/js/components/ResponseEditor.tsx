import React, { useState, useEffect } from 'react';

interface ResponseEditorProps {
    initialBody: string;
    onSend: (body: string) => Promise<void>;
    isSending: boolean;
    disabled?: boolean;
}

export const ResponseEditor: React.FC<ResponseEditorProps> = ({
    initialBody,
    onSend,
    isSending,
    disabled = false
}) => {
    const [body, setBody] = useState(initialBody);

    useEffect(() => {
        setBody(initialBody);
    }, [initialBody]);

    const handleSend = () => {
        if (!body.trim()) return;
        onSend(body);
    };

    return (
        <div className="bg-[#13131a] border border-white/5 rounded-2xl p-5 flex flex-col gap-4 shadow-xl">
            <div className="flex items-center justify-between">
                <h3 className="text-base font-semibold text-white flex items-center gap-2">
                    <span>✏️</span> Draft Editor
                </h3>
                <span className="text-xs text-gray-500 font-medium">
                    Changes made here will be sent directly to the thread
                </span>
            </div>

            <textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                disabled={disabled || isSending}
                rows={10}
                className="w-full bg-[#0a0a0c] border border-white/10 rounded-xl p-4 text-sm text-gray-200 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none resize-y min-h-[160px] leading-relaxed transition-all duration-200 disabled:opacity-50"
                placeholder="Compose your reply here..."
            />

            <div className="flex items-center justify-end gap-3 border-t border-white/5 pt-4">
                <button
                    onClick={handleSend}
                    disabled={disabled || isSending || !body.trim()}
                    className="px-5 py-2.5 bg-indigo-500 hover:bg-indigo-600 disabled:bg-indigo-500/50 text-white rounded-xl text-sm font-semibold shadow-lg shadow-indigo-500/10 hover:shadow-indigo-500/20 transition-all duration-200 flex items-center gap-2"
                >
                    {isSending ? (
                        <>
                            <div className="w-4 h-4 border-2 border-white/20 border-t-white rounded-full animate-spin" />
                            Sending Response...
                        </>
                    ) : (
                        <>
                            <span>📤</span> Send Response
                        </>
                    )}
                </button>
            </div>
        </div>
    );
};
