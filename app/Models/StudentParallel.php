<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentParallel extends Model
{
    protected $fillable = ['student_id', 'parallel_id', 'turno', 'status'];
                    
}
