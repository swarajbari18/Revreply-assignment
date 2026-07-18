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

export type WorkflowStatus =
    | 'queued'
    | 'processing'
    | 'context_built'
    | 'ai_complete'
    | 'decision_complete'
    | 'sent'
    | 'draft_created'
    | 'ignored'
    | 'failed';

export interface Classification {
    id: number;
    workflow_id: number;
    classification: 'interested' | 'not_interested' | 'meeting_request' | 'unclear';
    confidence: number;
    risk: number;
    generated_draft: string;
    prompt_version: string;
    model_version: string;
    created_at: string;
    updated_at: string;
}

export interface AuditLog {
    id: number;
    workflow_id: number;
    correlation_id: string;
    event: string;
    metadata: Record<string, any> | null;
    created_at: string;
    updated_at: string;
}

export interface Workflow {
    id: number;
    connected_account_id: number;
    thread_id: string | null;
    latest_message_id: string | null;
    status: WorkflowStatus;
    draft_id: string | null;
    failure_reason: string | null;
    correlation_id: string;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    classification?: Classification | null;
    audit_logs?: AuditLog[];
}

export interface Message {
    id: string;
    role: 'user' | 'assistant';
    body: string;
    timestamp: string;
    from: string;
    to: string;
    subject: string;
}
