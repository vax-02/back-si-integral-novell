<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentCareer extends Model
{
    protected $fillable = [
        'student_id',
        'career_id',
        'enrolled',
        'matricula',
        'status'
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
