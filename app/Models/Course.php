<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'career_id',
        'gestion',
        'paralelo',
        'limit',
        'turno',
    ];

    public function career()
    {
        return $this->belongsTo(Career::class);
    }

    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }
}
