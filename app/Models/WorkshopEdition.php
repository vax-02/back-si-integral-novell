<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopEdition extends Model
{
    protected $fillable = ['workshop_id', 'name', 'start_date', 'end_date', 'shift', 'capacity', 'status'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function workshop()
    {
        return $this->belongsTo(Workshop::class);
    }

    public function enrollments()
    {
        return $this->hasMany(WorkshopEnrollment::class);
    }

    public function concepts()
    {
        return $this->hasMany(WorkshopConcept::class);
    }

    public function grades()
    {
        return $this->hasMany(WorkshopGrade::class);
    }
}
