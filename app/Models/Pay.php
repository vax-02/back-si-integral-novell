<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pay extends Model
{
    protected $fillable = [
        'user_id',
        'student_id',
        'concept_id',
        'workshop_concept_id',
        'source',
        'amount',
        'discount',
        'description',
        'payment_method',
        'status',
    ];

    public function casher(){
        return $this->belongsTo(User::class,'user_id');
    }
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function concept()
    {
        return $this->belongsTo(Concept::class);
    }

    public function workshopConcept()
    {
        return $this->belongsTo(WorkshopConcept::class);
    }
}
