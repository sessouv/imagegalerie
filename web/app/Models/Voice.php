<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id', 'name', 'description', 'language', 'sample_path', 'sample_mime', 'consent_confirmed_at',
    'cleaned_path', 'use_cleaned', 'settings',
])]
class Voice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'consent_confirmed_at' => 'datetime',
            'use_cleaned' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Voice $voice) => Storage::delete(array_filter([$voice->sample_path, $voice->cleaned_path])));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usesCleaned(): bool
    {
        return $this->use_cleaned && $this->cleaned_path;
    }

    /** Échantillon envoyé au moteur : la version nettoyée si elle est activée. */
    public function activeSamplePath(): string
    {
        return $this->usesCleaned() ? $this->cleaned_path : $this->sample_path;
    }
}
