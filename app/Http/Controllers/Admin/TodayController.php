<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use App\Services\SubmissionDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "Today's Orders": which active stores have placed their order for a day and which haven't. */
class TodayController extends Controller
{
    public function __invoke(Request $request, SubmissionDeadline $deadlines): Response
    {
        $raw = (string) $request->query('date', '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false ? CarbonImmutable::parse($raw) : CarbonImmutable::today();
        $date = $day->toDateString();
        $isToday = $date === CarbonImmutable::today()->toDateString();

        $stores = Store::where('is_active', true)->withCount('users')->orderBy('name')->get();

        // pending + posted orders for that day (cancelled ones don't count, the store may order again)
        $orders = Order::whereIn('status', Order::ACTIVE)->where('order_date', $date)->withUnits()->with('user:id,name')->orderBy('id')->get()->groupBy('store_id');
        $inProgress = Order::where('status', Order::DRAFT)->where('order_date', $date)->whereHas('lines')->pluck('store_id')->flip();

        $ordered = [];
        $waiting = [];
        foreach ($stores as $store) {
            $d = $deadlines->for($store, $day);
            $base = ['id' => $store->id, 'code' => $store->code, 'name' => $store->name, 'ecpos_store_id' => $store->ecpos_store_id];

            if ($mine = $orders->get($store->id)) {
                $o = $mine->first();
                $ordered[] = $base + [
                    'order' => ['id' => $o->id, 'number' => $o->number(), 'status' => $o->status, 'units' => (int) $o->units, 'lines' => (int) $o->lines_count, 'by' => $o->user?->name, 'at' => $o->created_at->toIso8601String()],
                ];

                continue;
            }

            $waiting[] = $base + [
                'deadline_time' => $d['time'],
                'deadline_at' => $d['at']->toIso8601String(),
                'has_login' => $store->users_count > 0,
                'in_progress' => $inProgress->has($store->id), // started an order but hasn't placed it
            ];
        }

        // the stores closest to their cutoff first
        usort($waiting, fn ($a, $b) => [$a['deadline_at'], $a['name']] <=> [$b['deadline_at'], $b['name']]);

        return Inertia::render('admin/today', [
            'date' => $date,
            'is_today' => $isToday,
            'summary' => ['total' => $stores->count(), 'ordered' => count($ordered), 'waiting' => count($waiting), 'no_login' => collect($waiting)->where('has_login', false)->count()],
            'waiting' => $waiting,
            'ordered' => $ordered,
        ]);
    }
}
