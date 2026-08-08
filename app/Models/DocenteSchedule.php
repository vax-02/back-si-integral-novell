<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocenteSchedule extends Model
{
    protected $fillable = [
        'docente_id',
        'day',
        'entry_time',
        'is_active',
    ];

    public function docente()
    {
        return $this->belongsTo(Docente::class);
    }
}
