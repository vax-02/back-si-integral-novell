<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationColumn extends Model
{
    protected $fillable = [
        'subject_id',
        'parallel_id',
        'course_id',
        'name',
        'weight',
        'order',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function parallel()
    {
        return $this->belongsTo(Parallel::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }
}