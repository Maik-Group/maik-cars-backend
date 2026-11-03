<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            CarGroupSeeder::class,
            MobileRealDataSeeder::class,
            MobileFakeDataSeeder::class,
            AppVersionSeeder::class,
            RolesAndPermissionsSeeder::class,
            AuthUserSeeder::class,
            // Seed only the real Maik Cat offer catalog by default. The QA
            // wallet/account fixture seeder is an explicit opt-in for tests;
            // it must not populate storefront offers during ordinary seeding.
            // Stripe publication remains an explicit, guarded operator step.
            MaikCatCommercialOffersSeeder::class,
        ]);
    }
}
