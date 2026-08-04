<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parallel extends Model
{
    protected $fillable = [
        'course_id',
        'paralelo',
        'limit',
        'turno'
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
    public function students()
    {
        return $this->hasMany(StudentParallel::class);
    }
}
