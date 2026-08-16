<?php

namespace Database\Factories;

use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ⚠️ NO workspace_id, and no `assigned` status — R-4 and R-10.
 *
 * A code is platform inventory until an assignment row says otherwise, and
 * whether it is assigned is answered by the current-assignment index, never by
 * this factory's `status`.
 *
 * `public_token` uses random_bytes like the generator does, not `Str::random`:
 * a factory that produced weaker tokens than production would let a test about
 * token unpredictability pass for the wrong reason.
 *
 * @extends Factory<SmartQrCode>
 */
class SmartQrCodeFactory extends Factory
{
    protected $model = SmartQrCode::class;

    public function definition(): array
    {
        return [
            'serial_number' => Str::upper(Str::random(4)).'-'.fake()->unique()->numerify('######'),
            'public_token' => bin2hex(random_bytes(16)),
            'batch_id' => SmartQrBatch::factory(),
            'status' => SmartQrStatus::CODE_GENERATED,
        ];
    }

    public function printed(): static
    {
        return $this->state(fn () => [
            'status' => SmartQrStatus::CODE_PRINTED,
            'printed_at' => now(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => SmartQrStatus::CODE_RETIRED]);
    }
}
