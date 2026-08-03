<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => true, 'enabled' => true],
            ['code' => 'EUR', 'symbol' => '€', 'decimals' => 2, 'exchange_rate' => 0.92, 'is_default' => false, 'enabled' => true],
            ['code' => 'GBP', 'symbol' => '£', 'decimals' => 2, 'exchange_rate' => 0.79, 'is_default' => false, 'enabled' => true],
            ['code' => 'BDT', 'symbol' => '৳', 'decimals' => 2, 'exchange_rate' => 110.0, 'is_default' => false, 'enabled' => true],
        ];

        foreach ($currencies as $row) {
            Currency::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}
