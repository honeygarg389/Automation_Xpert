<?php

namespace Tests\Feature\Restaurant;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The stalled-event sweep is a SAFETY NET, never the primary processing path
 * (the controller dispatches the job immediately). A safety net that is not
 * actually scheduled is decoration, so this pins that it is registered — and
 * registered safely (no overlap, one server).
 */
class PosWebhookSweepScheduleTest extends TestCase
{
    #[Test]
    public function the_stalled_event_sweep_is_scheduled_every_five_minutes_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'restaurant:sweep-stalled-webhook-events'))
            ->values();

        $this->assertCount(1, $events, 'The sweep must be scheduled exactly once.');

        $event = $events->first();
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'The sweep must not overlap itself.');
        $this->assertTrue($event->onOneServer, 'The sweep must run on one server only.');
    }

    #[Test]
    public function the_sweep_command_is_registered(): void
    {
        $this->artisan('restaurant:sweep-stalled-webhook-events', ['--minutes' => 1, '--limit' => 1])
            ->expectsOutputToContain('Redispatched 0 stalled')
            ->assertSuccessful();
    }
}
