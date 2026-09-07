<?php

namespace Storyfeed\Tests;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\WithConfig;
use PHPUnit\Framework\Attributes\Test;
use Storyfeed\Models\Meta;

class CurateScheduleTest extends TestCase
{
    #[Test]
    public function it_registers_only_an_hourly_full_history_repair_without_running_it_at_boot(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame(Application::formatCommandString('storyfeed:curate'), $event->command);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(0, Meta::query()->count());

        $this->travelTo(now()->startOfHour());
        $this->assertCount(1, app(Schedule::class)->dueEvents($this->app));

        $this->travelTo(now()->addMinute());
        $this->assertCount(0, app(Schedule::class)->dueEvents($this->app));
    }

    #[Test]
    #[WithConfig('storyfeed.curate.schedule', false)]
    public function it_allows_consumers_to_disable_the_schedule_while_keeping_the_command_available(): void
    {
        $this->assertSame([], app(Schedule::class)->events());

        $this->artisan('storyfeed:curate')->assertSuccessful();
    }
}
