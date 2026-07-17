<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentCareer extends Model
{
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function career()
    {
        return $this->belongsTo(Career::class);
    }
}
