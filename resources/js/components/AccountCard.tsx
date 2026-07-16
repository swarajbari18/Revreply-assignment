import React, { useState } from 'react';
import { ConnectedAccount } from '../types';

interface AccountCardProps {
    account: ConnectedAccount;
    onDisconnect: (id: number) => Promise<void>;
    onReconnect: (email: string) => void;
}

export const AccountCard: React.FC<AccountCardProps> = ({ account, onDisconnect, onReconnect }) => {
    const [confirmDisconnect, setConfirmDisconnect] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const isConnected = account.status === 'connected';

    const getExpirationStatus = () => {
        if (!account.watch_expiration) return { label: 'No active watch', color: 'text-amber-500' };
        
        const expDate = new Date(account.watch_expiration);
        const now = new Date();
        const diffMs = expDate.getTime() - now.getTime();
        const diffHours = diffMs / (1000 * 60 * 60);

        if (diffMs < 0) {
            return { label: 'Watch expired', color: 'text-rose-500 font-semibold' };
        }

        const diffDays = Math.ceil(diffHours / 24);
        const label = diffDays === 1 ? 'Watch expires tomorrow' : `Watch expires in ${diffDays} days`;

        if (diffHours <= 48) {
            return { label: `${label} (Needs renewal)`, color: 'text-amber-500 font-semibold' };
        }

        return { label, color: 'text-gray-500' };
    };

    const expStatus = getExpirationStatus();

    const handleDelete = async () => {
        setIsDeleting(true);
        try {
            await onDisconnect(account.id);
        } catch (error) {
            setConfirmDisconnect(false);
            setIsDeleting(false);
        }
    };

    return (
        <div className="bg-[#13131a] border border-white/5 rounded-xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 transition-all hover:border-white/10 shadow-lg">
            <div className="flex items-start gap-4">
                <div className="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 font-bold shrink-0 mt-0.5">
                    G
                </div>
                <div>
                    <h3 className="text-base font-semibold text-white mb-1">
                        {account.gmail_email}
                    </h3>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span className="flex items-center gap-1.5 font-medium">
                            <span className={`w-2 h-2 rounded-full ${isConnected ? 'bg-green-500' : 'bg-rose-500'}`} />
                            <span className={isConnected ? 'text-green-400' : 'text-rose-400'}>
                                {isConnected ? 'Connected' : 'Disconnected'}
                            </span>
                        </span>
                        {isConnected && (
                            <>
                                <span className="text-white/20">•</span>
                                <span className={expStatus.color}>
                                    {expStatus.label}
                                </span>
                            </>
                        )}
                        {!isConnected && (
                            <>
                                <span className="text-white/20">•</span>
                                <span className="text-rose-400/80">
                                    Auth credentials revoked. Needs reconnection.
                                </span>
                            </>
                        )}
                    </div>
                </div>
            </div>

            <div className="flex items-center gap-2 self-end md:self-center shrink-0">
                {isConnected ? (
                    confirmDisconnect ? (
                        <div className="flex items-center gap-1">
                            <button
                                onClick={handleDelete}
                                disabled={isDeleting}
                                className="px-3 py-1.5 bg-rose-500/15 hover:bg-rose-500 text-rose-400 hover:text-white rounded-lg text-xs font-semibold border border-rose-500/30 transition-all duration-200"
                            >
                                {isDeleting ? 'Disconnecting...' : 'Yes, Disconnect'}
                            </button>
                            <button
                                onClick={() => setConfirmDisconnect(false)}
                                disabled={isDeleting}
                                className="px-3 py-1.5 bg-white/5 hover:bg-white/10 text-gray-300 rounded-lg text-xs font-semibold border border-white/5 transition-all duration-200"
                            >
                                Cancel
                            </button>
                        </div>
                    ) : (
                        <button
                            onClick={() => setConfirmDisconnect(true)}
                            className="px-3 py-1.5 bg-white/5 hover:bg-rose-500/10 text-gray-400 hover:text-rose-400 rounded-lg text-xs font-semibold border border-white/5 hover:border-rose-500/20 transition-all duration-200"
                        >
                            Disconnect
                        </button>
                    )
                ) : (
                    <button
                        onClick={() => onReconnect(account.gmail_email)}
                        className="px-4 py-1.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-lg text-xs font-semibold shadow-lg shadow-indigo-500/10 hover:shadow-indigo-500/20 transition-all duration-200"
                    >
                        Reconnect
                    </button>
                )}
            </div>
        </div>
    );
};
