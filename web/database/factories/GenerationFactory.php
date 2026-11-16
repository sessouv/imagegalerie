<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'text' => fake()->sentence(),
            'language' => 'fr',
            'settings' => ['exaggeration' => 0.5, 'cfg_weight' => 0.5, 'temperature' => 0.8, 'seed' => null],
            'status' => Generation::PENDING,
        ];
    }
}
