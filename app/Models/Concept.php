<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    protected $fillable = [
        'career_id',
        'type',
        'description',
        'gestion',
        'semestre',
        'amount',
    ];

    public function pays()
    {
        return $this->hasMany(Pay::class);
    }
    public function career(){
        return $this->belongsTo(Career::class);
    }
}
