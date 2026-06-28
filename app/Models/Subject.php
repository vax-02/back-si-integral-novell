<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'name',
        'sigla',
        'career_id',
    ];

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'course_subject');
    }
}
