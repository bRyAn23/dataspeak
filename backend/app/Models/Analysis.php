<?php

namespace App\Models;

use App\Enums\AnalysisStage;
use App\Enums\AnalysisStatus;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Central analysis aggregate (MOD-02). Holds lifecycle state and dataset metadata, never rows.
 */
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'CREATED',
        'current_stage' => 'UPLOAD',
    ];

    protected $fillable = [
        'name', 'status', 'current_stage', 'source_file_name', 'source_file_size_bytes', 'file_sha256',
        'row_count', 'column_count', 'completeness_pct', 'csv_uploaded_at', 'csv_purged_at',
        'last_error_code', 'last_error_message', 'chart_theme',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'current_stage' => AnalysisStage::class,
            'completeness_pct' => 'decimal:2',
            'label_translations' => 'array',
            'csv_uploaded_at' => 'datetime',
            'csv_purged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $analysis): void {
            $analysis->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    #[Scope]
    protected function ownedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /** True while a transient CSV is on disk for this analysis. */
    public function hasTransientFile(): bool
    {
        return $this->csv_uploaded_at !== null && $this->csv_purged_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AnalysisStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(AnalysisStageRecord::class);
    }

    /** @return HasMany<DatasetColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(DatasetColumn::class)->orderBy('position');
    }

    /** @return HasMany<Evidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /** @return HasMany<Insight, $this> */
    public function insights(): HasMany
    {
        return $this->hasMany(Insight::class)->orderBy('rank');
    }

    /** @return HasMany<Visualization, $this> */
    public function visualizations(): HasMany
    {
        return $this->hasMany(Visualization::class)->orderBy('position');
    }

    /** Charts the user kept for the report (FEAT-VIS-003); the report, narrative prompt and PDF use only these. */
    public function selectedVisualizations(): HasMany
    {
        return $this->visualizations()->where('is_selected', true);
    }

    /** @return HasMany<AiGeneration, $this> */
    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    /** @return HasMany<Claim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /** @return HasOne<Report, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }
}
