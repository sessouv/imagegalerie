<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName(),
            'language' => 'fr',
            'sample_path' => 'voices/'.fake()->uuid().'.wav',
            'sample_mime' => 'audio/wav',
            'consent_confirmed_at' => now(),
        ];
    }
}
