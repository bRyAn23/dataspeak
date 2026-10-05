<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Executive report metadata/content (MOD-11). One per analysis. */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected $fillable = ['status', 'title', 'generated_at', 'claims_total', 'claims_supported', 'summary_metrics', 'sections', 'data_notes', 'language'];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'generated_at' => 'datetime',
            'summary_metrics' => 'array',
            'sections' => 'array',
            'data_notes' => 'array',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
