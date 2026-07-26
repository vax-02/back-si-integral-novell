<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Qualification extends Model
{
    protected $fillable = [
        'student_id',
        'course_id',
        'parallel_id',
        'subject_id',
        'qualification',
        'final_grade',
        'published',
    ];

    protected $casts = [
        'published' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function parallel()
    {
        return $this->belongsTo(Parallel::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function details()
    {
        return $this->hasMany(QualificationDetail::class);
    }
}