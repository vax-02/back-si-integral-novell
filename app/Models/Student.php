<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'career_id',
        'user_id',
        'degree_id',
        'turno',
        'matricula',
        'birth_certificate',
        'school_diploma',
        'carnet',
        'status',
    ];

    public function career()
    {
        return $this->belongsTo(Career::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function degree()
    {
        return $this->belongsTo(Degree::class);
    }

    public function pays()
    {
        return $this->hasMany(Pay::class);
    }

    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }
}
