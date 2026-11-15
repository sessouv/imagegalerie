<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'author' => $this->user?->name,
            'created_at' => $this->created_at->toIso8601String(),
            'audio_url' => $this->status === self::COMPLETED ? route('generations.audio', $this) : null,
        ];
    }
}
