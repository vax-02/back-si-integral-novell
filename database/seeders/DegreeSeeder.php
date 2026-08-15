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
        Degree::firstOrCreate(['name' => 'Licenciado(a)'], ['abbreviation' => 'Lic.']);
        Degree::firstOrCreate(['name' => 'Ingeniero(a)'], ['abbreviation' => 'Ing.']);
        Degree::firstOrCreate(['name' => 'Técnico Superior'], ['abbreviation' => 'T.S.']);
        Degree::firstOrCreate(['name' => 'Técnico Medio'], ['abbreviation' => 'T.M.']);
    }
}
