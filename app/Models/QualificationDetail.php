<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualificationDetail extends Model
{
    protected $fillable = [
        'qualification_id',
        'evaluation_column_id',
        'grade',
    ];

    public function qualification()
    {
        return $this->belongsTo(Qualification::class);
    }

    public function evaluationColumn()
    {
        return $this->belongsTo(EvaluationColumn::class);
    }
}