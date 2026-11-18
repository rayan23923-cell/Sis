<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PolicySeeder::class, AdminSeeder::class]);
        if (config('sis.demo_data')) {
            $this->call(DemoSeeder::class);
        }
    }
}
