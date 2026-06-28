<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Docente extends Model
{
    protected $fillable = ['user_id', 'career_id', 'cv', 'professional_title', 'carnet', 'certificate', 'status'];
    
}
