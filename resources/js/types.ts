export type AccountStatus = 'connected' | 'disconnected';

export interface ConnectedAccount {
    id: number;
    user_id: number;
    gmail_email: string;
    status: AccountStatus;
    watch_expiration: string | null;
    created_at: string;
    updated_at: string;
}

export interface DemoUser {
    id: number;
    name: string;
    email: string;
    avatarColor: string;
}
