<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pay extends Model
{
    protected $fillable = [
        'user_id',
        'student_id',
        'concept_id',
        'amount',
        'discount',
        'status',
    ];

    public function casher(){
        return $this->belogsTo(User::class);
    }
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function concept()
    {
        return $this->belongsTo(Concept::class);
    }
}
