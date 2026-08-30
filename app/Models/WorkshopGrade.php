<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopGrade extends Model
{
    protected $fillable = ['student_id', 'workshop_module_id', 'workshop_edition_id', 'score'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function module()
    {
        return $this->belongsTo(WorkshopModule::class);
    }

    public function edition()
    {
        return $this->belongsTo(WorkshopEdition::class, 'workshop_edition_id');
    }
}
