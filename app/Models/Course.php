<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'career_id',
        'name',
        'level',
    ];

    public function career()
    {
        return $this->belongsTo(Career::class);
    }

    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }
    public function parallels(){
        return $this->hasMany(Parallel::class);
    }
}
