<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'token_hash', 'hint', 'last_used_at'])]
class ApiToken extends Model
{
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crée une clé. La valeur en clair n'est renvoyée qu'ici : elle n'est pas conservée.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = 'timbre_'.Str::random(48);

        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'hint' => substr($plain, 0, 11),
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }
}
