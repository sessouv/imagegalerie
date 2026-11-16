<?php

namespace App\Models;

use App\Jobs\TranscribeAudio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'name', 'audio_path', 'language', 'detected_language', 'status', 'progress',
    'engine_job_id', 'duration_ms', 'text', 'segments', 'error',
])]
class Transcription extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['segments' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Transcription $t) => Storage::delete($t->audio_path));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [Generation::PENDING, Generation::PROCESSING], true);
    }

    /** Enregistre le fichier et lance la transcription. */
    public static function start(User $user, UploadedFile $file, ?string $language): self
    {
        $path = $file->storeAs('transcriptions', Str::uuid().'.'.($file->extension() ?: 'bin'));

        $transcription = self::create([
            'user_id' => $user->id,
            'name' => Str::limit($file->getClientOriginalName() ?: 'Sans nom', 250, ''),
            'audio_path' => $path,
            'language' => $language,
            'status' => Generation::PENDING,
        ]);
        TranscribeAudio::dispatch($transcription);

        return $transcription;
    }

    /** Relance une transcription en échec. */
    public function enqueue(): void
    {
        $this->update(['status' => Generation::PENDING, 'progress' => 0, 'engine_job_id' => null, 'error' => null]);
        TranscribeAudio::dispatch($this);
    }

    /** Sous-titres au format SRT. */
    public function toSrt(): string
    {
        $time = function (float $seconds): string {
            $ms = (int) round($seconds * 1000);

            return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
        };

        $blocks = [];
        foreach ($this->segments ?? [] as $i => $segment) {
            $blocks[] = ($i + 1)."\n".$time($segment['start']).' --> '.$time($segment['end'])."\n".$segment['text']."\n";
        }

        return implode("\n", $blocks);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->detected_language ?? $this->language,
            'status' => $this->status,
            'progress' => $this->progress,
            'duration_ms' => $this->duration_ms,
            'text' => $this->text,
            'error' => $this->error,
            'author' => $this->user?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
