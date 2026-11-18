<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'is_current' => 'boolean'];

    public function terms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class)->orderBy('starts_on');
    }

    public static function current(): ?self
    {
        return static::where('is_current', true)->first() ?? static::orderByDesc('starts_on')->first();
    }
}
