import React, { useState, useEffect, useCallback } from 'react';
import { ConnectedAccount, Workflow, Message, WorkflowStatus } from '../types';
import { ThreadMessageBubble } from './ThreadMessageBubble';
import { AuditTimeline } from './AuditTimeline';
import { ResponseEditor } from './ResponseEditor';

interface WorkflowWorkspaceProps {
    account: ConnectedAccount;
    onBack: () => void;
    showToast: (message: string, type: 'success' | 'error') => void;
}

export const WorkflowWorkspace: React.FC<WorkflowWorkspaceProps> = ({ account, onBack, showToast }) => {
    const [workflows, setWorkflows] = useState<Workflow[]>([]);
    const [selectedWorkflow, setSelectedWorkflow] = useState<Workflow | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [isWorkflowsLoading, setIsWorkflowsLoading] = useState(true);
    const [isDetailsLoading, setIsDetailsLoading] = useState(false);
    const [isActioning, setIsActioning] = useState(false);
    const [filterStatus, setFilterStatus] = useState<string>('all');

    // Fetch workflows list
    const fetchWorkflows = useCallback(async () => {
        setIsWorkflowsLoading(true);
        try {
            let url = `/api/workflows?account_id=${account.id}`;
            if (filterStatus !== 'all') {
                url += `&status=${filterStatus}`;
            }
            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to fetch workflows');
            const data = await response.json();
            setWorkflows(data.data || data);
        } catch (error) {
            showToast('Unable to load workflows.', 'error');
        } finally {
            setIsWorkflowsLoading(false);
        }
    }, [account.id, filterStatus, showToast]);

    useEffect(() => {
        fetchWorkflows();
    }, [fetchWorkflows]);

    // Fetch workflow detail & messages
    const fetchWorkflowDetails = useCallback(async (workflowId: number) => {
        setIsDetailsLoading(true);
        try {
            const response = await fetch(`/api/workflows/${workflowId}`);
            if (!response.ok) throw new Error('Failed to fetch details');
            const data = await response.json();
            setSelectedWorkflow(data.workflow);
            setMessages(data.messages || []);
        } catch (error) {
            showToast('Failed to load thread details.', 'error');
        } finally {
            setIsDetailsLoading(false);
        }
    }, [showToast]);

    // Select workflow
    const handleSelectWorkflow = (workflow: Workflow) => {
        fetchWorkflowDetails(workflow.id);
    };

    // Actions: Approve
    const handleApprove = async () => {
        if (!selectedWorkflow) return;
        setIsActioning(true);
        try {
            const response = await fetch(`/api/workflows/${selectedWorkflow.id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Failed to approve draft');
            }
            showToast('Draft approved and response sent!', 'success');
            // Refresh details & list
            await fetchWorkflowDetails(selectedWorkflow.id);
            fetchWorkflows();
        } catch (error: any) {
            showToast(error.message || 'Failed to approve draft.', 'error');
        } finally {
            setIsActioning(false);
        }
    };

    // Actions: Reject
    const handleReject = async () => {
        if (!selectedWorkflow) return;
        setIsActioning(true);
        try {
            const response = await fetch(`/api/workflows/${selectedWorkflow.id}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Failed to reject draft');
            }
            showToast('Draft rejected and deleted from Gmail.', 'success');
            await fetchWorkflowDetails(selectedWorkflow.id);
            fetchWorkflows();
        } catch (error: any) {
            showToast(error.message || 'Failed to reject draft.', 'error');
        } finally {
            setIsActioning(false);
        }
    };

    // Actions: Custom Send
    const handleCustomSend = async (body: string) => {
        if (!selectedWorkflow) return;
        setIsActioning(true);
        try {
            const response = await fetch(`/api/workflows/${selectedWorkflow.id}/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ body })
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Failed to send response');
            }
            showToast('Custom response sent successfully!', 'success');
            await fetchWorkflowDetails(selectedWorkflow.id);
            fetchWorkflows();
        } catch (error: any) {
            showToast(error.message || 'Failed to send response.', 'error');
        } finally {
            setIsActioning(false);
        }
    };

    const getStatusBadge = (status: WorkflowStatus) => {
        switch (status) {
            case 'queued':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-500/10 text-gray-400 border border-gray-500/20">Queued</span>;
            case 'processing':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 animate-pulse">Processing</span>;
            case 'context_built':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">Context Built</span>;
            case 'ai_complete':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-400 border border-purple-500/20">AI Evaluated</span>;
            case 'decision_complete':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-pink-500/10 text-pink-400 border border-pink-500/20">Decision Made</span>;
            case 'sent':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-500/10 text-green-400 border border-green-500/20">Replied / Sent</span>;
            case 'draft_created':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">Draft Pending</span>;
            case 'ignored':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-white/5 text-gray-400 border border-white/5">Ignored</span>;
            case 'failed':
                return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">Failed / Escalate</span>;
            default:
                return null;
        }
    };

    const getClassificationColor = (classification: string) => {
        switch (classification) {
            case 'interested':
                return 'text-green-400';
            case 'meeting_request':
                return 'text-blue-400';
            case 'not_interested':
                return 'text-rose-400';
            default:
                return 'text-amber-400';
        }
    };

    return (
        <div className="min-h-screen flex flex-col bg-[#0a0a0c]">
            {/* Header bar */}
            <div className="h-16 border-b border-white/5 px-6 flex items-center justify-between bg-[#0e0e12] shrink-0">
                <div className="flex items-center gap-3">
                    <button
                        onClick={onBack}
                        className="p-2 hover:bg-white/5 rounded-lg text-gray-400 hover:text-white transition-all duration-200"
                        title="Back to Mailboxes"
                    >
                        ⬅
                    </button>
                    <div>
                        <h2 className="text-sm font-semibold text-white leading-none mb-1">
                            {account.gmail_email}
                        </h2>
                        <p className="text-[10px] text-gray-500 font-mono leading-none">
                            Mailbox ID: {account.id}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        onClick={fetchWorkflows}
                        className="px-3 py-1.5 bg-white/5 hover:bg-white/10 text-gray-300 rounded-lg text-xs font-semibold border border-white/5 transition-all duration-200"
                    >
                        🔄 Refresh
                    </button>
                </div>
            </div>

            {/* Split pane Workspace */}
            <div className="flex-1 flex overflow-hidden">
                {/* Left Panel: Workflows List */}
                <div className="w-80 border-r border-white/5 flex flex-col bg-[#0e0e12] shrink-0 overflow-y-auto">
                    {/* Filters */}
                    <div className="p-4 border-b border-white/5 shrink-0">
                        <select
                            value={filterStatus}
                            onChange={(e) => setFilterStatus(e.target.value)}
                            className="w-full bg-[#13131a] border border-white/10 rounded-lg py-1.5 px-3 text-xs text-gray-300 focus:border-indigo-500 outline-none transition-all duration-200"
                        >
                            <option value="all">All Statuses</option>
                            <option value="draft_created">Draft Pending</option>
                            <option value="failed">Failed / Escalated</option>
                            <option value="sent">Replied / Sent</option>
                            <option value="ignored">Ignored</option>
                            <option value="queued">Queued / Processing</option>
                        </select>
                    </div>

                    {/* Workflow Items */}
                    <div className="flex-1">
                        {isWorkflowsLoading ? (
                            <div className="flex flex-col items-center justify-center py-10 gap-3">
                                <div className="w-6 h-6 border-2 border-indigo-500/20 border-t-indigo-500 rounded-full animate-spin" />
                                <p className="text-gray-500 text-[11px]">Loading workflows...</p>
                            </div>
                        ) : workflows.length > 0 ? (
                            <div className="divide-y divide-white/5">
                                {workflows.map((wf) => {
                                    const isSelected = selectedWorkflow?.id === wf.id;
                                    return (
                                        <button
                                            key={wf.id}
                                            onClick={() => handleSelectWorkflow(wf)}
                                            className={`w-full text-left p-4 transition-all duration-200 flex flex-col gap-2 hover:bg-white/5 ${
                                                isSelected ? 'bg-indigo-500/5 border-l-2 border-indigo-500' : ''
                                            }`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-[10px] text-gray-500 font-mono">
                                                    #{wf.id} • {new Date(wf.created_at).toLocaleDateString()}
                                                </span>
                                                {getStatusBadge(wf.status)}
                                            </div>

                                            <div className="text-xs font-semibold text-white line-clamp-1">
                                                {wf.classification?.classification ? (
                                                    <span>
                                                        Intent:{' '}
                                                        <span className={getClassificationColor(wf.classification.classification)}>
                                                            {wf.classification.classification}
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400">Thread Context Resolving</span>
                                                )}
                                            </div>

                                            <div className="text-[11px] text-gray-400 font-mono truncate">
                                                ID: {wf.correlation_id.substring(0, 16)}...
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="text-center py-12 px-4 text-gray-500 text-xs">
                                No workflows found matching filter.
                            </div>
                        )}
                    </div>
                </div>

                {/* Right Panel: Selected Workflow Detail Workspace */}
                <div className="flex-1 flex overflow-hidden">
                    {selectedWorkflow ? (
                        isDetailsLoading ? (
                            <div className="flex-1 flex flex-col items-center justify-center gap-4">
                                <div className="w-10 h-10 border-4 border-indigo-500/20 border-t-indigo-500 rounded-full animate-spin" />
                                <p className="text-gray-500 text-sm">Fetching thread history & messages...</p>
                            </div>
                        ) : (
                            <div className="flex-1 flex overflow-hidden">
                                {/* Details & Chat bubble pane */}
                                <div className="flex-1 flex flex-col overflow-y-auto p-6 bg-[#0a0a0c]">
                                    {/* Workflow Metadata Banner */}
                                    <div className="bg-[#13131a] border border-white/5 rounded-2xl p-5 mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-xl">
                                        <div>
                                            <div className="flex items-center gap-3 mb-2 flex-wrap">
                                                <h2 className="text-lg font-bold text-white">
                                                    Workflow #{selectedWorkflow.id}
                                                </h2>
                                                {getStatusBadge(selectedWorkflow.status)}
                                            </div>
                                            <p className="text-xs text-gray-400 font-mono">
                                                Correlation ID: {selectedWorkflow.correlation_id}
                                            </p>
                                        </div>

                                        {selectedWorkflow.classification && (
                                            <div className="flex items-center gap-6 border-t md:border-t-0 md:border-l border-white/5 pt-4 md:pt-0 md:pl-6">
                                                <div className="text-center">
                                                    <div className="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Intent</div>
                                                    <span className={`text-sm font-bold ${getClassificationColor(selectedWorkflow.classification.classification)}`}>
                                                        {selectedWorkflow.classification.classification}
                                                    </span>
                                                </div>
                                                <div className="text-center">
                                                    <div className="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Confidence</div>
                                                    <span className="text-sm font-bold text-white">
                                                        {Math.round(selectedWorkflow.classification.confidence * 100)}%
                                                    </span>
                                                </div>
                                                <div className="text-center">
                                                    <div className="text-[10px] text-gray-500 uppercase tracking-wider mb-1">Risk Score</div>
                                                    <span className="text-sm font-bold text-white">
                                                        {Math.round(selectedWorkflow.classification.risk * 100)}%
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {/* Escalation/Failure Reason Notice */}
                                    {selectedWorkflow.status === 'failed' && selectedWorkflow.failure_reason && (
                                        <div className="bg-rose-500/10 border border-rose-500/20 rounded-2xl p-4 mb-6 flex items-start gap-3">
                                            <span className="text-lg">⚠️</span>
                                            <div>
                                                <h4 className="text-sm font-semibold text-rose-400 mb-1">
                                                    Workflow Escalated for Manual Review
                                                </h4>
                                                <p className="text-xs text-rose-300 leading-relaxed">
                                                    Reason: {selectedWorkflow.failure_reason.replace(/_/g, ' ')}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {/* Chat Messages */}
                                    <div className="flex-1 mb-8">
                                        <h3 className="text-sm font-semibold text-gray-400 mb-4 uppercase tracking-wider">
                                            Gmail Thread Messages
                                        </h3>
                                        {messages.length > 0 ? (
                                            <div>
                                                {messages.map((message) => (
                                                    <ThreadMessageBubble key={message.id} message={message} />
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="text-center py-10 bg-white/5 border border-white/5 rounded-2xl text-gray-500 text-sm">
                                                No emails resolved in this thread yet.
                                            </div>
                                        )}
                                    </div>

                                    {/* Actions Composer / Editor */}
                                    {selectedWorkflow.status === 'draft_created' && (
                                        <div className="flex flex-col gap-6">
                                            {/* Primary Approve/Reject bar */}
                                            <div className="bg-[#13131a] border border-white/5 rounded-2xl p-4 flex items-center justify-between gap-4 shadow-lg">
                                                <div>
                                                    <h4 className="text-xs font-semibold text-gray-300 mb-1">
                                                        Gmail Auto-Generated Draft Response
                                                    </h4>
                                                    <p className="text-[10px] text-gray-500">
                                                        Approve to send as-is, reject to discard, or customize below.
                                                    </p>
                                                </div>

                                                <div className="flex items-center gap-3 shrink-0">
                                                    <button
                                                        onClick={handleReject}
                                                        disabled={isActioning}
                                                        className="px-4 py-2 bg-rose-500/10 hover:bg-rose-500 text-rose-400 hover:text-white rounded-xl text-xs font-semibold border border-rose-500/20 transition-all duration-200"
                                                    >
                                                        🗑️ Reject & Delete
                                                    </button>
                                                    <button
                                                        onClick={handleApprove}
                                                        disabled={isActioning}
                                                        className="px-4 py-2 bg-green-500 hover:bg-green-600 disabled:bg-green-500/50 text-white rounded-xl text-xs font-semibold shadow-lg shadow-green-500/10 hover:shadow-green-500/20 transition-all duration-200"
                                                    >
                                                        {isActioning ? 'Sending...' : '✅ Approve & Send'}
                                                    </button>
                                                </div>
                                            </div>

                                            {/* Composer */}
                                            <ResponseEditor
                                                initialBody={selectedWorkflow.classification?.generated_draft || ''}
                                                onSend={handleCustomSend}
                                                isSending={isActioning}
                                            />
                                        </div>
                                    )}

                                    {/* Manual resolution composer for failed/escalated */}
                                    {selectedWorkflow.status === 'failed' && (
                                        <ResponseEditor
                                            initialBody={selectedWorkflow.classification?.generated_draft || ''}
                                            onSend={handleCustomSend}
                                            isSending={isActioning}
                                        />
                                    )}
                                </div>

                                {/* Audit Timeline side-pane */}
                                <div className="w-80 border-l border-white/5 bg-[#0e0e12] p-6 overflow-y-auto shrink-0 hidden lg:block">
                                    <h3 className="text-sm font-semibold text-gray-400 mb-6 uppercase tracking-wider flex items-center justify-between">
                                        <span>📋 Workflow Audit Log</span>
                                    </h3>
                                    <AuditTimeline logs={selectedWorkflow.audit_logs || []} />
                                </div>
                            </div>
                        )
                    ) : (
                        <div className="flex-1 flex flex-col items-center justify-center text-center p-8 bg-[#070709]">
                            <div className="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center text-gray-400 text-2xl mb-4 border border-white/5">
                                🖱️
                            </div>
                            <h3 className="text-lg font-semibold text-white mb-1">
                                No Workflow Selected
                            </h3>
                            <p className="text-gray-500 text-xs max-w-xs">
                                Select a workflow thread from the left sidebar to view metadata analysis, email history, and action resolution options.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};
