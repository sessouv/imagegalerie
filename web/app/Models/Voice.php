<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'name', 'description', 'language', 'sample_path', 'sample_mime', 'consent_confirmed_at'])]
class Voice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['consent_confirmed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Voice $voice) => Storage::delete($voice->sample_path));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
