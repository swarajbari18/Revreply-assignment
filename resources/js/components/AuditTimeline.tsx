import React from 'react';
import { AuditLog } from '../types';

interface AuditTimelineProps {
    logs: AuditLog[];
}

export const AuditTimeline: React.FC<AuditTimelineProps> = ({ logs }) => {
    const getEventDetails = (event: string, metadata: any) => {
        switch (event) {
            case 'workflow_picked_up':
                return {
                    label: 'Workflow Picked Up',
                    desc: 'Background processor started executing this request.',
                    color: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
                    icon: '🚀'
                };
            case 'context_built':
                return {
                    label: 'Context Built',
                    desc: `Loaded ${metadata?.message_count || 0} message(s) and resolved thread context.`,
                    color: 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30',
                    icon: '📂'
                };
            case 'ai_classification_completed':
                return {
                    label: 'AI Classification',
                    desc: `Intent: "${metadata?.classification}", Confidence: ${Math.round(metadata?.confidence * 100)}%, Risk: ${Math.round(metadata?.risk * 100)}%`,
                    color: 'bg-purple-500/20 text-purple-400 border-purple-500/30',
                    icon: '🧠'
                };
            case 'decision_engine_completed':
                return {
                    label: 'Decision Logged',
                    desc: `Action: ${metadata?.decision} (Reason: ${metadata?.reason?.replace(/_/g, ' ')})`,
                    color: 'bg-pink-500/20 text-pink-400 border-pink-500/30',
                    icon: '⚖️'
                };
            case 'draft_created':
                return {
                    label: 'Draft Created',
                    desc: `Gmail draft ID: ${metadata?.draft_id?.substring(0, 10)}... pending review.`,
                    color: 'bg-amber-500/20 text-amber-400 border-amber-500/30',
                    icon: '📝'
                };
            case 'email_sent':
                return {
                    label: 'Email Sent',
                    desc: `Automatically replied to ${metadata?.recipient || 'prospect'}.`,
                    color: 'bg-green-500/20 text-green-400 border-green-500/30',
                    icon: '📤'
                };
            case 'draft_approved_and_sent':
                return {
                    label: 'Approved & Sent',
                    desc: 'User approved draft response from the dashboard.',
                    color: 'bg-green-500/20 text-green-400 border-green-500/30',
                    icon: '✅'
                };
            case 'draft_sent_with_user_edits':
                return {
                    label: 'Sent (Direct Edits)',
                    desc: 'User edited draft on Gmail directly before approving.',
                    color: 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30',
                    icon: '✏️'
                };
            case 'draft_edited_and_sent':
                return {
                    label: 'Customized & Sent',
                    desc: 'User custom-edited and sent this draft from the editor.',
                    color: 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30',
                    icon: '✉️'
                };
            case 'workflow_rejected':
                return {
                    label: 'Draft Rejected',
                    desc: 'Draft deleted from Gmail and thread ignored.',
                    color: 'bg-red-500/20 text-red-400 border-red-500/30',
                    icon: '🗑️'
                };
            case 'workflow_ignored':
                return {
                    label: 'Workflow Ignored',
                    desc: `Skipped auto-reply: ${metadata?.reason?.replace(/_/g, ' ') || 'Conditions not met.'}`,
                    color: 'bg-gray-500/20 text-gray-400 border-gray-500/30',
                    icon: '⏭️'
                };
            case 'execution_aborted_stale_context':
                return {
                    label: 'Aborted (Stale)',
                    desc: 'Gmail history moved forward while processing; execution canceled.',
                    color: 'bg-rose-500/20 text-rose-400 border-rose-500/30',
                    icon: '⚠️'
                };
            case 'gmail_auth_revoked':
                return {
                    label: 'OAuth Revoked',
                    desc: 'Gmail credentials expired or access disconnected.',
                    color: 'bg-rose-500/20 text-rose-400 border-rose-500/30',
                    icon: '🚫'
                };
            default:
                return {
                    label: event.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
                    desc: JSON.stringify(metadata) || '',
                    color: 'bg-white/5 text-gray-400 border-white/10',
                    icon: 'ℹ️'
                };
        }
    };

    const formatTime = (dateStr: string) => {
        try {
            return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        } catch (e) {
            return dateStr;
        }
    };

    if (logs.length === 0) {
        return (
            <div className="text-center py-6 text-gray-500 text-sm">
                No audit events recorded for this workflow.
            </div>
        );
    }

    return (
        <div className="relative pl-6 border-l border-white/5 space-y-6">
            {logs.map((log) => {
                const details = getEventDetails(log.event, log.metadata);
                return (
                    <div key={log.id} className="relative group">
                        {/* Timeline Node Icon */}
                        <div className="absolute -left-[35px] top-0.5 w-6 h-6 rounded-full bg-[#13131a] border border-white/10 flex items-center justify-center text-xs shadow-md group-hover:border-white/20 transition-all duration-200">
                            {details.icon}
                        </div>

                        {/* Card Content */}
                        <div className="bg-[#13131a]/60 border border-white/5 rounded-xl p-3.5 hover:border-white/10 transition-all duration-200">
                            <div className="flex items-center justify-between gap-2 mb-1">
                                <span className={`px-2 py-0.5 rounded-full text-xs font-semibold border ${details.color}`}>
                                    {details.label}
                                </span>
                                <span className="text-[10px] text-gray-500 font-mono">
                                    {formatTime(log.created_at)}
                                </span>
                            </div>
                            <p className="text-gray-400 text-xs leading-relaxed">
                                {details.desc}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};
