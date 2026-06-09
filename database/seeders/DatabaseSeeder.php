<?php

namespace Database\Seeders;

use App\Models\Product;
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
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Product::query()->insert([
            [
                'name' => 'Demo Keyboard',
                'stock' => 40,
                'price_cents' => 7999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Demo Mouse',
                'stock' => 60,
                'price_cents' => 2999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Demo Monitor',
                'stock' => 20,
                'price_cents' => 24999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Optional large dataset for Task 4 batch tally demos (set BULK_ORDER_SEED_COUNT or use default).
        // $this->call(BulkOrdersForTallyDemoSeeder::class);
    }
}
