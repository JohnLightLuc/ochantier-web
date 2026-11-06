<?php

namespace Database\Seeders;

use App\Models\User;
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
        User::factory()->create([
            'name' => 'N. Yao',
            'email' => 'n.yao@ochantier.ci',
        ]);

        $this->call([PromoterSeeder::class, WorkerSeeder::class, SalesAgentSeeder::class, SiteSeeder::class, NeedSeeder::class, PromoterOfferSeeder::class, MatchingSeeder::class, AttendanceSeeder::class, PaymentSeeder::class, FinanceSeeder::class, QualitySeeder::class, AnalyticsSeeder::class, SettingsSeeder::class]);
    }
}
