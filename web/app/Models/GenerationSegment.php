<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['generation_id', 'position', 'text', 'ends_paragraph', 'status', 'attempts', 'audio_path', 'duration_ms', 'error', 'voice_id', 'voice_name'])]
class GenerationSegment extends Model
{
    protected function casts(): array
    {
        return ['ends_paragraph' => 'boolean'];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class);
    }

    public function present(): array
    {
        $done = $this->status === Generation::COMPLETED;

        return [
            'id' => $this->id,
            'position' => $this->position,
            'text' => $this->text,
            'voice_name' => $this->voice_name ?? 'Voix par défaut',
            'status' => $this->status,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'audio_url' => $done
                ? route('generations.segments.audio', [$this->generation_id, $this]).'?v='.$this->attempts
                : null,
        ];
    }
}
