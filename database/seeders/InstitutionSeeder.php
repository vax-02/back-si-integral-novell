<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Institution;


class InstitutionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Institution::firstOrCreate([
            'address' => 'Calle A entre B',
            'cellphone' => '72345287',
            'email' => 'novel@gmail.com'
        ]);
    }
}
