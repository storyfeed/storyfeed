<?php

namespace Storyfeed\Tests;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\WithConfig;
use PHPUnit\Framework\Attributes\Test;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Meta;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

class CurateScheduleTest extends TestCase
{
    #[Test]
    public function it_registers_only_an_hourly_windowed_repair_without_running_it_at_boot(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertCount(1, $events);
        $event = $events[0];

        // The unattended run is BOUNDED. Every shipped axis key ends in `:d`,
        // so a past day's cluster cannot gain members and re-deciding it
        // hourly reaches yesterday's answer at yesterday's cost.
        $this->assertSame(
            Application::formatCommandString('storyfeed:curate').' --window=2',
            $event->command,
        );
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(0, Meta::query()->count());

        $this->travelTo(now()->startOfHour());
        $this->assertCount(1, app(Schedule::class)->dueEvents($this->app));

        $this->travelTo(now()->addMinute());
        $this->assertCount(0, app(Schedule::class)->dueEvents($this->app));
    }

    /**
     * The command's OWN default is untouched. A human typing
     * `php artisan storyfeed:curate` is making the explicit "do everything"
     * request — after an import, after adding an axis — and must still get it.
     */
    #[Test]
    public function the_bare_command_is_still_unbounded(): void
    {
        $this->travelTo(now()->subMonths(6));

        $ancient = Storyfeed::activity()
            ->actor(User::create(['name' => 'Ines', 'email' => 'ines@example.com']))
            ->verb('order.note', Customer::create(['name' => 'Order 1001']))
            ->publish();

        $this->travelBack();

        Grouping::query()->where('activity_id', $ancient->getKey())->update(['winner' => null]);

        $this->artisan('storyfeed:curate')->expectsOutputToContain('Curated 1 activities.')->assertSuccessful();

        $this->assertTrue(
            (bool) Grouping::query()->where('activity_id', $ancient->getKey())->where('winner', true)->exists(),
        );
    }

    /**
     * And the window the SCHEDULE passes really does bound it — asserted on
     * behaviour rather than on the command string, because the string only
     * proves the flag was typed, not that it was honoured.
     */
    #[Test]
    public function the_scheduled_invocation_skips_activities_older_than_the_window(): void
    {
        $ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
        $order = Customer::create(['name' => 'Order 1001']);

        $this->travelTo(now()->subMonths(6));
        $ancient = Storyfeed::activity()->actor($ines)->verb('order.note', $order)->publish();
        $this->travelBack();

        $recent = Storyfeed::activity()->actor($ines)->verb('order.note', $order)->publish();

        Grouping::query()->update(['winner' => null]);

        // Run exactly what the scheduler registered, flags and all, rather than
        // a hand-typed copy of it that could drift from the real invocation.
        $scheduled = app(Schedule::class)->events()[0]->command;
        $flags = Str::after($scheduled, Application::formatCommandString('storyfeed:curate'));

        Artisan::call('storyfeed:curate'.$flags);

        $this->assertFalse(
            (bool) Grouping::query()->where('activity_id', $ancient->getKey())->where('winner', true)->exists(),
            'the ancient activity is outside the window and must be left alone',
        );
        $this->assertTrue(
            (bool) Grouping::query()->where('activity_id', $recent->getKey())->where('winner', true)->exists(),
            'the head of the feed is what the schedule exists to keep correct',
        );
    }

    #[Test]
    #[WithConfig('storyfeed.curate.window', null)]
    public function null_restores_the_unbounded_hourly_pass(): void
    {
        $this->assertSame(
            Application::formatCommandString('storyfeed:curate'),
            app(Schedule::class)->events()[0]->command,
        );
    }

    #[Test]
    #[WithConfig('storyfeed.curate.window', 0)]
    public function zero_restores_the_unbounded_hourly_pass(): void
    {
        $this->assertSame(
            Application::formatCommandString('storyfeed:curate'),
            app(Schedule::class)->events()[0]->command,
        );
    }

    /**
     * A consumer running a custom axis that does NOT pin the day has no closed
     * clusters, and the config comment tells them to widen the window. It has
     * to actually be widenable.
     */
    #[Test]
    #[WithConfig('storyfeed.curate.window', 30)]
    public function the_window_is_configurable_for_axes_that_do_not_pin_the_day(): void
    {
        $this->assertSame(
            Application::formatCommandString('storyfeed:curate').' --window=30',
            app(Schedule::class)->events()[0]->command,
        );
    }

    #[Test]
    #[WithConfig('storyfeed.curate.schedule', false)]
    public function it_allows_consumers_to_disable_the_schedule_while_keeping_the_command_available(): void
    {
        $this->assertSame([], app(Schedule::class)->events());

        $this->artisan('storyfeed:curate')->assertSuccessful();
    }
}
