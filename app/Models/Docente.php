<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Docente extends Model
{
    protected $fillable = [
        'user_id',
        'degree_id',
        'cv',
        'professional_title',
        'carnet',
        'certificate',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function degree()
    {
        return $this->belongsTo(Degree::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'docente_subject')
                    ->withPivot(['parallel_id', 'subject_id','status'])
                    ->withTimestamps();
    }

    public function parallelAssignments()
    {
        return $this->belongsToMany(Parallel::class, 'docente_subject', 'docente_id', 'parallel_id')
                    ->withPivot(['subject_id', 'status'])
                    ->withTimestamps();
    }
}