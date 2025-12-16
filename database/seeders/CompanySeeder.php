<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::create([
            'name' => 'Pet Shop Alpha',
            'whatsapp_number' => '14157386102',
        ]);

        Company::create([
            'name' => 'Pet Shop Beta',
            'whatsapp_number' => '14157380000',
        ]);
    }
}
