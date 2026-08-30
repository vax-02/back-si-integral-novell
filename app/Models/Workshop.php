<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workshop extends Model
{
    protected $fillable = ['name', 'description', 'status'];

    public function modules()
    {
        return $this->hasMany(WorkshopModule::class)->orderBy('order');
    }

    public function editions()
    {
        return $this->hasMany(WorkshopEdition::class);
    }
}
