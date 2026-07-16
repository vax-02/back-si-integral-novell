<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    protected $fillable = [
        'career_id',
        'name',
        'type',
        'description',
        'gestion',
        'semestre',
        'amount',
    ];

    public function pays()
    {
        return $this->hasMany(Pay::class);
    }
}
