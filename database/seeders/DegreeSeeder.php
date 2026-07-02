<?php

namespace Database\Seeders;

use App\Models\Degree;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DegreeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Degree::firstOrCreate(['name' => 'Licenciado(a)']);
        Degree::firstOrCreate(['name' => 'Ingeniero(a)']);
        Degree::firstOrCreate(['name' => 'Técnico Superior']);
        Degree::firstOrCreate(['name' => 'Técnico Medio']);
    }
}
