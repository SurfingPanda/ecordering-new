<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Audit Log": one read-only timeline of what happened to orders (placed, posted, cancelled, reset, sent to ECPOS, failed),
 * every change to the ordering deadline, and the security / admin activity (sign-ins, failed sign-ins, password changes,
 * logins switched on or off, store and catalog changes), newest first. Admin only.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    private const PER_PAGE_CHOICES = [10, 25, 50, 100];

    private const ORDER_EVENTS = ['placed', 'posted', 'cancelled', 'reset', 'sent', 'send_failed', 'send_requested'];

    public function __invoke(Request $request): Response
    {
        $activityEvents = array_keys(Activity::EVENTS);
        $type = in_array($request->query('type'), [...self::ORDER_EVENTS, 'deadline', ...$activityEvents], true) ? (string) $request->query('type') : null;
        $storeId = Store::whereKey((int) $request->query('store'))->value('id');
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $search = trim((string) $request->query('search'));
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE_CHOICES, true) ? (int) $request->query('per_page') : self::PER_PAGE;

        // all three sources share one column list so they can be stacked and paged together
        $null = fn (string $as) => DB::raw("null as {$as}");

        $orders = DB::table('order_events as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->select([
                DB::raw("'order' as kind"), 'e.id', 'e.created_at', 'e.event', 'e.note', 'e.user_id', 'o.store_id', 'e.order_id',
                $null('previous_time'), $null('new_time'), $null('applies_on'), $null('subject'), $null('ip'),
            ])
            ->when($type, fn ($q) => $q->where('e.event', $type))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('o.tr_number', 'like', "%{$search}%")->orWhere('o.ecpos_journal_id', 'like', "%{$search}%")));

        $deadlines = DB::table('deadline_changes as d')->select([
            DB::raw("'deadline' as kind"), 'd.id', 'd.created_at', DB::raw("'deadline' as event"), $null('note'), 'd.changed_by as user_id', 'd.store_id', $null('order_id'),
            'd.previous_time', 'd.new_time', 'd.applies_on', $null('subject'), $null('ip'),
        ]);

        $activity = DB::table('activity_log as a')
            ->select([
                DB::raw("'activity' as kind"), 'a.id', 'a.created_at', 'a.event', 'a.note', 'a.user_id', 'a.store_id', $null('order_id'),
                $null('previous_time'), $null('new_time'), $null('applies_on'), 'a.subject', 'a.ip',
            ])
            ->when($type, fn ($q) => $q->where('a.event', $type))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('a.subject', 'like', "%{$search}%")->orWhere('a.note', 'like', "%{$search}%")));

        // an order event, a deadline change and an activity entry each belong to one kind of "what happened" filter
        $isOrderType = $type !== null && in_array($type, self::ORDER_EVENTS, true);
        $isActivityType = $type !== null && in_array($type, $activityEvents, true);

        $parts = [];
        if ($type === null || $isOrderType) {
            $parts[] = $orders;
        }
        if (($type === null || $type === 'deadline') && $search === '') {
            $parts[] = $deadlines; // a deadline change has no order, name or email to search
        }
        if ($type === null || $isActivityType) {
            $parts[] = $activity;
        }

        $union = array_shift($parts) ?? $orders->whereRaw('1 = 0');
        foreach ($parts as $part) {
            $union = $union->unionAll($part);
        }

        $page = DB::query()->fromSub($union, 'a')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();

        $rows = collect($page->items());
        $users = User::whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $stores = Store::whereIn('id', $rows->pluck('store_id')->filter()->unique())->get(['id', 'code', 'name'])->keyBy('id');
        $orderRows = Order::whereIn('id', $rows->pluck('order_id')->filter()->unique())->get()->keyBy('id');

        $page->setCollection($rows->map(function ($r) use ($users, $stores, $orderRows) {
            $store = $stores->get($r->store_id);
            $order = $r->order_id ? $orderRows->get($r->order_id) : null;

            return [
                'key' => $r->kind.'-'.$r->id,
                'at' => Carbon::parse($r->created_at)->toIso8601String(),
                'event' => $r->event,
                'by' => $r->user_id ? ($users[$r->user_id] ?? 'Deleted user') : null, // null = the system, or nobody was signed in
                'store' => $store ? $store->code.' — '.$store->name : ($r->kind === 'deadline' ? 'All stores (default)' : null),
                'order' => $order ? ['id' => $order->id, 'number' => $order->number()] : null,
                'subject' => $r->subject,
                'ip' => $r->ip,
                'note' => $r->note,
                'deadline' => $r->kind === 'deadline' ? ['previous' => $r->previous_time, 'new' => $r->new_time, 'applies_on' => $r->applies_on] : null,
            ];
        }));

        return Inertia::render('admin/audit-log', [
            'entries' => $page,
            'filters' => ['type' => $type, 'store' => $storeId, 'from' => $from, 'to' => $to, 'search' => $search, 'per_page' => $perPage],
            'stores' => Store::orderBy('name')->get(['id', 'code', 'name']),
            'event_labels' => collect(Activity::EVENTS)->map(fn ($e) => ['label' => $e[0], 'group' => $e[1]]),
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }
}
