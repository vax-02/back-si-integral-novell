<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocenteSubject extends Model
{
    protected $table = 'docente_subject';
    protected $fillable=[
        'docente_id',
        'subject_id',
        'parallel_id',
        'status'
    ];
}
