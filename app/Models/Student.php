<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = [
        'career_id',
        'user_id',
        'birth_certificate',
        'school_diploma',
        'carnet',
        'status',
    ];

    public function studentCareers()
    {
        return $this->hasMany(StudentCareer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }


    public function pays()
    {
        return $this->hasMany(Pay::class);
    }

    public function parallels()
    {
        return $this->hasMany(StudentParallel::class);
    }
    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }
}
