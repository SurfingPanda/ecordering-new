<?php

namespace Tests\Feature;

use App\Mail\DailySummaryMail;
use App\Models\StoreDeadline;
use App\Models\User;
use App\Services\DailySummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class DailySummaryTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['notifications.admin_emails' => ['boss@bw.test'], 'notifications.daily_summary' => true, 'notifications.daily_summary_admins' => true]);

        $items = $this->makeItems();
        $today = now()->toDateString();
        // Alpha and Beta ordered (7 and 3 units); Gamma has not; Delta is not the manager's; Omega is inactive
        foreach (['AL' => [5, 2], 'BE' => [3, 0], 'GA' => null, 'DE' => [9, 1]] as $code => $qty) {
            $store = $this->makeStore($code, true, 'Store '.$code);
            $user = $this->makeStoreUser($store, strtolower($code).'@store.test');
            if ($qty) {
                $this->seedOrder($store, $user, 'posted', $today, array_values(array_filter([[$items['bw'], $qty[0]], $qty[1] ? [$items['wh'], $qty[1]] : null])));
            }
            $this->s[$code] = $store;
        }
        $this->makeStore('OM', false, 'Store OM'); // inactive: not expected to order

        $this->manager = new User(['name' => 'Maria Santos', 'email' => 'maria@bw.test', 'password' => 'secret-pass-1']);
        $this->manager->forceFill(['role' => User::AREA_MANAGER, 'store_id' => null])->save();
        $this->manager->managedStores()->sync([$this->s['AL']->id, $this->s['BE']->id, $this->s['GA']->id]);
    }

    private User $manager;

    public function test_the_summary_counts_who_ordered_and_who_did_not_for_the_given_stores(): void
    {
        $mine = app(DailySummary::class)->build($this->manager->visibleStoreIds(), CarbonImmutable::today());

        $this->assertSame([3, 2, 1, 10], [$mine['total'], $mine['ordered'], $mine['waiting'], $mine['units']]);
        $this->assertSame(['Store AL', 'Store BE'], array_column($mine['ordered_stores'], 'name'));
        $this->assertSame(['Store GA'], array_column($mine['waiting_stores'], 'name'));
        $this->assertSame('16:00', $mine['waiting_stores'][0]['cutoff']);

        $all = app(DailySummary::class)->build(null, CarbonImmutable::today());
        $this->assertSame([4, 3, 1, 20], [$all['total'], $all['ordered'], $all['waiting'], $all['units']]); // every ACTIVE store; the inactive one is left out
    }

    public function test_a_stores_own_later_deadline_and_cancelled_and_failed_orders_show_up(): void
    {
        StoreDeadline::create(['store_id' => $this->s['GA']->id, 'date' => now()->toDateString(), 'time' => '23:59']);
        $be = \App\Models\Order::where('store_id', $this->s['BE']->id)->first();
        $be->forceFill(['ecpos_state' => 'failed'])->save();
        $this->seedOrder($this->s['AL'], User::where('email', 'al@store.test')->first(), 'cancelled', now()->toDateString(), []);

        $mine = app(DailySummary::class)->build($this->manager->visibleStoreIds(), CarbonImmutable::today());

        $this->assertSame('23:59', $mine['waiting_stores'][0]['cutoff']); // that store has until late
        $this->assertSame(1, $mine['ecpos_problems']);
        $this->assertSame(1, $mine['cancelled']);
        $this->assertSame(2, $mine['ordered']); // a cancelled TR doesn't count as ordered
    }

    public function test_the_command_sends_each_manager_theirs_and_the_admins_everything(): void
    {
        $other = new User(['name' => 'Other Manager', 'email' => 'other@bw.test', 'password' => 'secret-pass-1']);
        $other->forceFill(['role' => User::AREA_MANAGER, 'store_id' => null])->save();
        $other->managedStores()->sync([$this->s['DE']->id]);
        $empty = new User(['name' => 'No Stores', 'email' => 'empty@bw.test', 'password' => 'secret-pass-1']);
        $empty->forceFill(['role' => User::AREA_MANAGER, 'store_id' => null])->save(); // nothing to summarise: not emailed

        $this->artisan('orders:daily-summary')->assertSuccessful();

        Mail::assertQueued(DailySummaryMail::class, 3);
        Mail::assertQueued(DailySummaryMail::class, fn ($m) => $m->hasTo('maria@bw.test') && $m->summary['total'] === 3 && $m->scope === '3 stores you handle');
        Mail::assertQueued(DailySummaryMail::class, fn ($m) => $m->hasTo('other@bw.test') && $m->summary['total'] === 1 && $m->scope === '1 store you handle');
        Mail::assertQueued(DailySummaryMail::class, fn ($m) => $m->hasTo('boss@bw.test') && $m->summary['total'] === 4 && $m->scope === 'all stores');
        Mail::assertNotQueued(DailySummaryMail::class, fn ($m) => $m->hasTo('empty@bw.test'));
    }

    public function test_it_can_be_switched_off_or_limited_and_checks_its_date(): void
    {
        config(['notifications.daily_summary' => false]);
        $this->artisan('orders:daily-summary')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->artisan('orders:daily-summary --force --only=MARIA@bw.test')->assertSuccessful();
        Mail::assertQueued(DailySummaryMail::class, 1);

        config(['notifications.daily_summary' => true, 'notifications.daily_summary_admins' => false]);
        Mail::fake();
        $this->artisan('orders:daily-summary')->assertSuccessful();
        Mail::assertNotQueued(DailySummaryMail::class, fn ($m) => $m->hasTo('boss@bw.test')); // the admin copy has its own switch
        Mail::assertQueued(DailySummaryMail::class, 1);

        $this->artisan('orders:daily-summary --date=yesterday')->assertFailed();
    }

    public function test_the_email_reads_well(): void
    {
        $m = new DailySummaryMail('Maria', '3 stores you handle', app(DailySummary::class)->build($this->manager->visibleStoreIds(), CarbonImmutable::today()));

        $this->assertStringContainsString('2 of 3 stores ordered (1 not ordered)', $m->envelope()->subject);
        $html = $m->render();
        $this->assertStringContainsString('Hi Maria', $html);
        $this->assertStringContainsString('2 of 3 stores have ordered today', $html);
        $this->assertStringContainsString('Haven&rsquo;t ordered (1)', $html);
        $this->assertStringContainsString('Store GA', $html);
        $this->assertStringContainsString('Store AL', $html);
        $this->assertStringNotContainsString('Store DE', $html); // not this manager's store
        $this->assertStringContainsString('/admin/today?date='.now()->toDateString(), $html);
        $this->assertStringContainsString('<img src="data:image/png', $html);

        // and when everyone ordered
        $this->seedOrder($this->s['GA'], User::where('email', 'ga@store.test')->first(), 'posted', now()->toDateString(), [[\App\Models\Item::first(), 1]]);
        $done = new DailySummaryMail('Maria', '3 stores you handle', app(DailySummary::class)->build($this->manager->visibleStoreIds(), CarbonImmutable::today()));
        $this->assertStringContainsString('every store ordered', $done->envelope()->subject);
        $this->assertStringContainsString('every one of your 3 stores has ordered today', $done->render());
    }
}
