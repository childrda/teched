<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Model events must stay enabled: page_id/block_id ULIDs and JSON
     * defaults are assigned in creating hooks.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            WeldingLessonSeeder::class,
        ]);
    }
}
