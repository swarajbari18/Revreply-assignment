import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { UserPicker } from './components/UserPicker';
import { Dashboard } from './components/Dashboard';
import { WorkflowWorkspace } from './components/WorkflowWorkspace';
import { Toast } from './components/Toast';
import { DemoUser, ConnectedAccount } from './types';

const App = () => {
    const [selectedUser, setSelectedUser] = useState<DemoUser | null>(null);
    const [selectedAccount, setSelectedAccount] = useState<ConnectedAccount | null>(null);
    const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' } | null>(null);

    // Read selected user from localStorage on mount
    useEffect(() => {
        const storedUser = localStorage.getItem('revreply_demo_user');
        if (storedUser) {
            try {
                setSelectedUser(JSON.parse(storedUser));
            } catch (e) {
                localStorage.removeItem('revreply_demo_user');
            }
        }

        // Parse query params for connection status notifications
        const params = new URLSearchParams(window.location.search);
        if (params.has('connected')) {
            setToast({ message: 'Gmail mailbox connected successfully.', type: 'success' });
            // Clean URL query parameters
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (params.has('error')) {
            const error = params.get('error');
            const message = error === 'oauth_denied' 
                ? 'Authorization denied. Access to Gmail accounts is required.' 
                : 'Failed to connect Gmail mailbox. Please try again.';
            setToast({ message, type: 'error' });
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }, []);

    const handleSelectUser = (user: DemoUser) => {
        localStorage.setItem('revreply_demo_user', JSON.stringify(user));
        setSelectedUser(user);
    };

    const handleSwitchUser = () => {
        localStorage.removeItem('revreply_demo_user');
        setSelectedAccount(null);
        setSelectedUser(null);
    };

    const showToast = (message: string, type: 'success' | 'error') => {
        setToast({ message, type });
    };

    return (
        <div className="min-h-screen bg-[#0a0a0c] text-gray-200">
            {selectedUser ? (
                selectedAccount ? (
                    <WorkflowWorkspace
                        account={selectedAccount}
                        onBack={() => setSelectedAccount(null)}
                        showToast={showToast}
                    />
                ) : (
                    <Dashboard
                        user={selectedUser}
                        onSwitchUser={handleSwitchUser}
                        showToast={showToast}
                        onSelectAccount={setSelectedAccount}
                    />
                )
            ) : (
                <UserPicker onSelectUser={handleSelectUser} />
            )}

            {toast && (
                <Toast
                    message={toast.message}
                    type={toast.type}
                    onClose={() => setToast(null)}
                />
            )}
        </div>
    );
};

// Mount the React Application
const container = document.getElementById('app');
if (container) {
    const root = createRoot(container);
    root.render(
        <React.StrictMode>
            <App />
        </React.StrictMode>
    );
}
