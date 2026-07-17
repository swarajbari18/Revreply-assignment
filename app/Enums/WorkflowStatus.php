<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case ContextBuilt = 'context_built';
    case AiComplete = 'ai_complete';
    case DecisionComplete = 'decision_complete';
    case DraftCreated = 'draft_created';
    case Sent = 'sent';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
