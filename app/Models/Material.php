<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $fillable = [
        'docente_id',
        'subject_id',
        'title',
        'description',
        'file_path',
        'file_name',
        'file_type',
        'all_parallels',
    ];

    protected $casts = [
        'all_parallels' => 'boolean',
    ];

    public function docente()
    {
        return $this->belongsTo(Docente::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function parallels()
    {
        return $this->belongsToMany(Parallel::class, 'material_parallel');
    }
}