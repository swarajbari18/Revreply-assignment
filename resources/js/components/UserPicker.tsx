import React from 'react';
import { DemoUser } from '../types';

interface UserPickerProps {
    onSelectUser: (user: DemoUser) => void;
}

const DEMO_USERS: DemoUser[] = [
    {
        id: 1,
        name: 'Swaraj Demo',
        email: 'swaraj@demo.com',
        avatarColor: 'from-[#ff4b2b] to-[#ff416c]',
    },
    {
        id: 2,
        name: 'Bari Demo',
        email: 'bari@demo.com',
        avatarColor: 'from-[#3a7bd5] to-[#3a6073]',
    },
];

export const UserPicker: React.FC<UserPickerProps> = ({ onSelectUser }) => {
    return (
        <div className="flex flex-col items-center justify-center min-h-[80vh] px-4">
            <div className="w-full max-w-2xl text-center mb-10">
                <h1 className="text-4xl font-bold tracking-tight text-white mb-3">
                    RevReply
                </h1>
                <p className="text-gray-400 text-lg">
                    Select a demo workspace profile to manage connected accounts
                </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 w-full max-w-2xl">
                {DEMO_USERS.map((user) => (
                    <button
                        key={user.id}
                        onClick={() => onSelectUser(user)}
                        className="flex flex-col items-center p-8 bg-[#13131a] hover:bg-[#1a1a24] border border-white/5 hover:border-white/10 rounded-2xl shadow-xl transition-all duration-300 transform hover:-translate-y-1 group text-center"
                    >
                        <div className={`w-20 h-20 rounded-full bg-gradient-to-br ${user.avatarColor} flex items-center justify-center text-white text-3xl font-bold mb-5 shadow-lg group-hover:scale-105 transition-transform duration-300`}>
                            {user.name.split(' ').map(n => n[0]).join('')}
                        </div>
                        <h2 className="text-xl font-semibold text-white mb-1 group-hover:text-indigo-400 transition-colors duration-300">
                            {user.name}
                        </h2>
                        <p className="text-gray-500 text-sm">
                            {user.email}
                        </p>
                        <div className="mt-6 px-4 py-2 bg-white/5 group-hover:bg-indigo-500/10 rounded-lg text-xs font-semibold text-gray-400 group-hover:text-indigo-400 border border-white/5 transition-all duration-300">
                            Enter Workspace
                        </div>
                    </button>
                ))}
            </div>
        </div>
    );
};
