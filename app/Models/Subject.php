<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'name',
        'sigla',
        'level',
        'career_id',
        'subject_id',
    ];

    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }

    public function career()
    {
        return $this->belongsTo(Career::class);
    }

    public function prerequisite()
    {
        return $this->belongsTo(self::class, 'subject_id');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function docentes()
    {
        return $this->belongsToMany(Docente::class, 'docente_subject')
                    ->withPivot(['parallel_id', 'status'])
                    ->withTimestamps();
    }

    public function activeDocentes()
    {
        return $this->belongsToMany(Docente::class, 'docente_subject')
                    ->withPivot(['parallel_id', 'status'])
                    ->wherePivot('status', true)
                    ->withTimestamps();
    }
}