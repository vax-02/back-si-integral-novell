<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Qualification extends Model
{
    protected $fillable = [
        'student_id',
        'course_id',
        'subject_id',
        'qualification',
    ];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'qualification_student');
    }
}
