<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AllowedGoogleAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'default_role_slug',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->email = Str::lower(trim((string) $account->email));
        });
    }
}
