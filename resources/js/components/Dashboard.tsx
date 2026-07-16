import React, { useState, useEffect, useCallback } from 'react';
import { ConnectedAccount, DemoUser } from '../types';
import { AccountCard } from './AccountCard';

interface DashboardProps {
    user: DemoUser;
    onSwitchUser: () => void;
    showToast: (message: string, type: 'success' | 'error') => void;
}

export const Dashboard: React.FC<DashboardProps> = ({ user, onSwitchUser, showToast }) => {
    const [accounts, setAccounts] = useState<ConnectedAccount[]>([]);
    const [isLoading, setIsLoading] = useState(true);

    const fetchAccounts = useCallback(async () => {
        setIsLoading(true);
        try {
            const response = await fetch(`/api/accounts?user_id=${user.id}`);
            if (!response.ok) throw new Error('Failed to fetch accounts');
            const data = await response.json();
            setAccounts(data);
        } catch (error) {
            showToast('Unable to load connected accounts.', 'error');
        } finally {
            setIsLoading(false);
        }
    }, [user.id, showToast]);

    useEffect(() => {
        fetchAccounts();
    }, [fetchAccounts]);

    const handleConnect = () => {
        window.location.href = `/auth/gmail/connect?user_id=${user.id}`;
    };

    const handleReconnect = (email: string) => {
        // Redirects to connect which upserts the credentials
        window.location.href = `/auth/gmail/connect?user_id=${user.id}&login_hint=${encodeURIComponent(email)}`;
    };

    const handleDisconnect = async (id: number) => {
        try {
            const response = await fetch(`/api/accounts/${id}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Failed to delete account');

            // Remove account from local state
            setAccounts(prev => prev.filter(acc => acc.id !== id));
            showToast('Account disconnected successfully.', 'success');
        } catch (error) {
            showToast('Failed to disconnect account. Please try again.', 'error');
            throw error;
        }
    };

    return (
        <div className="max-w-4xl mx-auto py-10 px-4">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/5 pb-8 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-white mb-1">
                        Connected Mailboxes
                    </h1>
                    <p className="text-gray-400 text-sm">
                        Manage Gmail integrations for <span className="text-indigo-400 font-semibold">{user.name}</span>
                    </p>
                </div>

                <div className="flex items-center gap-4">
                    <button
                        onClick={onSwitchUser}
                        className="px-4 py-2 bg-white/5 hover:bg-white/10 text-gray-300 rounded-lg text-sm font-semibold border border-white/5 transition-all duration-200"
                    >
                        Switch Workspace
                    </button>
                    <button
                        onClick={handleConnect}
                        className="px-4 py-2 bg-indigo-500 hover:bg-indigo-600 text-white rounded-lg text-sm font-semibold shadow-lg shadow-indigo-500/10 hover:shadow-indigo-500/20 transition-all duration-200"
                    >
                        Connect Mailbox
                    </button>
                </div>
            </div>

            {/* List */}
            {isLoading ? (
                <div className="flex flex-col items-center justify-center py-20 gap-4">
                    <div className="w-10 h-10 border-4 border-indigo-500/20 border-t-indigo-500 rounded-full animate-spin" />
                    <p className="text-gray-500 text-sm">Loading connected mailboxes...</p>
                </div>
            ) : accounts.length > 0 ? (
                <div className="flex flex-col gap-4">
                    {accounts.map(account => (
                        <AccountCard
                            key={account.id}
                            account={account}
                            onDisconnect={handleDisconnect}
                            onReconnect={handleReconnect}
                        />
                    ))}
                </div>
            ) : (
                <div className="flex flex-col items-center justify-center border border-dashed border-white/10 rounded-2xl py-20 px-6 text-center">
                    <div className="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center text-gray-400 text-2xl mb-4 border border-white/5">
                        ✉
                    </div>
                    <h2 className="text-xl font-semibold text-white mb-2">
                        No Mailboxes Connected
                    </h2>
                    <p className="text-gray-500 max-w-sm text-sm mb-6">
                        Connect a Gmail account to begin monitoring incoming messages and automating classification.
                    </p>
                    <button
                        onClick={handleConnect}
                        className="px-5 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-lg text-sm font-semibold shadow-lg shadow-indigo-500/10 hover:shadow-indigo-500/20 transition-all duration-200"
                    >
                        Connect Your First Mailbox
                    </button>
                </div>
            )}
        </div>
    );
};
