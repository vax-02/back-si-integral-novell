<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'parallel_id',
        'day',
        'start_time',
        'end_time',
        'subject_id',
    ];

    public function parallel()
    {
        return $this->belongsTo(Parallel::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}