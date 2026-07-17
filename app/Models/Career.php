<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Career extends Model
{
    protected $fillable = ['name', 'duration', 'type', 'status'];

    public function students()
    {
        return $this->hasMany(StudentCareer::class);
    }
    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }
}
