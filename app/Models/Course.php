<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'status',
    ];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'course_student');
    }
}
