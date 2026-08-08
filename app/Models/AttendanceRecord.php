<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'docente_id',
        'biometric_pin',
        'clock_at',
        'verify',
        'workcode',
    ];

    protected $casts = [
        'clock_at' => 'datetime',
    ];

    public function docente()
    {
        return $this->belongsTo(Docente::class);
    }
}
