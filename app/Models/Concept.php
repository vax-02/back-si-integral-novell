<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    protected $fillable = [
        'name',
    ];

    public function pays()
    {
        return $this->hasMany(Pay::class);
    }
}
