<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'term', 'replacement', 'language', 'case_sensitive'])]
class Pronunciation extends Model
{
    protected function casts(): array
    {
        return ['case_sensitive' => 'boolean'];
    }
}
