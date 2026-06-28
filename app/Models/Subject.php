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

    public function qualifications()
    {
        return $this->hasMany(Qualification::class);
    }

    public function career()
    {
        return $this->belongsTo(Career::class);
    }
}
