<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentConvalidation extends Model
{
    protected $fillable = [
        'student_id',
        'career_id',
        'type',
        'start_level',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function career()
    {
        return $this->belongsTo(Career::class);
    }
}
