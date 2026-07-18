<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Classification extends Model
{
    protected $fillable = [
        'workflow_id',
        'classification',
        'confidence',
        'risk',
        'generated_draft',
        'prompt_version',
        'model_version',
    ];

    protected $casts = [
        'confidence' => 'double',
        'risk' => 'double',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
