<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopEnrollment extends Model
{
    protected $fillable = ['student_id', 'workshop_edition_id', 'enrolled', 'code', 'status'];

    protected function casts(): array
    {
        return [
            'enrolled' => 'date',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function edition()
    {
        return $this->belongsTo(WorkshopEdition::class, 'workshop_edition_id');
    }

    public function grades()
    {
        return $this->hasMany(WorkshopGrade::class);
    }
}
