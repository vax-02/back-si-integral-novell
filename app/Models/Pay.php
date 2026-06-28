<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pay extends Model
{
    protected $fillable = [
        'student_id',
        'concept_id',
        'amount',
        'discount',
        'status',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function concept()
    {
        return $this->belongsTo(Concept::class);
    }
}
