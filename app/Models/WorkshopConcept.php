<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopConcept extends Model
{
    protected $fillable = ['workshop_edition_id', 'type', 'description', 'amount'];

    public function edition()
    {
        return $this->belongsTo(WorkshopEdition::class, 'workshop_edition_id');
    }
}
