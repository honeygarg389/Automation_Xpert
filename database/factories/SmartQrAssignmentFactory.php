<?php

namespace Database\Factories;

use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Where tenancy lives, and where it ends.
 *
 * ⚠️ `workspace_id` has NO default. An assignment without a workspace is not an
 * assignment, and a factory that invented one would let a test pass while
 * asserting nothing about the tenant boundary — so callers must state it.
 *
 * @extends Factory<SmartQrAssignment>
 */
class SmartQrAssignmentFactory extends Factory
{
    protected $model = SmartQrAssignment::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'smart_qr_code_id' => SmartQrCode::factory(),
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'assigned_at' => now()->subDay(),
        ];
    }

    /**
     * A CLOSED period — the shape that makes the R-7 gauge filter matter.
     *
     * An unfiltered count treats these as current, so a workspace that churned
     * codes would read as being at its limit while owning nothing.
     */
    public function ended(): static
    {
        return $this->state(fn () => [
            'unassigned_at' => now()->subHour(),
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
        ]);
    }
}
