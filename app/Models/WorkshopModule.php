<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopModule extends Model
{
    protected $fillable = ['workshop_id', 'name', 'order'];

    public function workshop()
    {
        return $this->belongsTo(Workshop::class);
    }

    public function grades()
    {
        return $this->hasMany(WorkshopGrade::class);
    }
}
