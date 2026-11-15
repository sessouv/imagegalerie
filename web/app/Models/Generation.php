<?php

namespace App\Models;

use App\Jobs\GenerateSpeech;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'voice_id', 'voice_name', 'text', 'language', 'settings', 'status', 'audio_path', 'duration_ms', 'error'])]
class Generation extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleted(function (Generation $generation) {
            Storage::deleteDirectory($generation->directory());
            // Audios créés avant le découpage en passages
            if ($generation->audio_path) {
                Storage::delete($generation->audio_path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(GenerationSegment::class)->orderBy('position');
    }

    /** Charge l'avancement (passages terminés / total) avec la liste. */
    public function scopeWithProgress(Builder $query): void
    {
        $query->with('user:id,name')->withCount([
            'segments',
            'segments as segments_done_count' => fn (Builder $q) => $q->where('status', self::COMPLETED),
        ]);
    }

    public function directory(): string
    {
        return 'generations/'.$this->id;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }

    /**
     * Lance (ou relance) la génération. Si elle tourne déjà, le traitement en
     * cours prendra en compte les passages remis en attente : pas de doublon.
     */
    public function enqueue(): void
    {
        $wasRunning = $this->exists && $this->isRunning() && ! $this->wasRecentlyCreated;

        $this->update(['status' => self::PENDING, 'error' => null]);

        if (! $wasRunning) {
            GenerateSpeech::dispatch($this);
        }
    }

    /** Forme envoyée à l'interface. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'language' => $this->language,
            'voice_id' => $this->voice_id,
            'voice_name' => $this->voice_name ?? 'Voix par défaut',
            'settings' => $this->settings,
            'status' => $this->status,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'segments_total' => (int) $this->segments_count,
            'segments_done' => (int) $this->segments_done_count,
            'author' => $this->user?->name,
            'created_at' => $this->created_at->toIso8601String(),
            'audio_url' => $this->status === self::COMPLETED
                ? route('generations.audio', $this).'?v='.$this->updated_at->timestamp
                : null,
        ];
    }
}
