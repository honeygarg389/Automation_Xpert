<?php

namespace Database\Factories;

use App\Modules\SmartQr\Models\SmartQrBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A print run. Platform-owned — no workspace, at any point in its life.
 *
 * ⚠️ `prefix` is randomised per batch, not fixed. `serial_number` is GLOBALLY
 * unique, so two factory batches sharing a prefix and a serial_start collide at
 * generation — which is real behaviour (SerialRangeAvailable exists for it) but
 * would make unrelated tests fail for a reason they are not about.
 *
 * @extends Factory<SmartQrBatch>
 */
class SmartQrBatchFactory extends Factory
{
    protected $model = SmartQrBatch::class;

    public function definition(): array
    {
        return [
            'batch_number' => 'AX-BK-'.Str::upper(Str::random(8)),
            'batch_name' => fake()->words(3, true),
            'prefix' => Str::upper(Str::random(4)),
            'quantity' => 10,
            'serial_start' => 1,
            'status' => 'draft',
        ];
    }
}
