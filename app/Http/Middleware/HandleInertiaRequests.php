<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\SubmissionDeadline;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('store');

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
            'auth' => [
                'user' => $user ? [
                    ...$user->only('id', 'name', 'email', 'role'),
                    'store' => $user->store?->only('id', 'code', 'name', 'is_active'),
                ] : null,
            ],
            // today's submission deadline, for store accounts (drives the banner and disabled states)
            'deadline' => fn () => $user?->isStore() && $user->store ? $this->deadlineFor($user->store) : null,
            // admins: how many active stores haven't ordered yet today (sidebar badge + the reminder before the cutoff)
            'ordering_today' => fn () => $user?->isAdmin() ? $this->orderingToday() : null,
        ];
    }

    /** Cheap summary for the admin sidebar: the standard cutoff (stores may have their own, see Today's Orders). */
    private function orderingToday(): array
    {
        $service = app(SubmissionDeadline::class);
        $total = Store::where('is_active', true)->count();
        $ordered = \App\Models\Order::whereIn('status', \App\Models\Order::ACTIVE)->where('order_date', today()->toDateString())
            ->whereIn('store_id', Store::where('is_active', true)->select('id'))->distinct()->count('store_id');
        [$h, $mi] = array_map('intval', explode(':', $service->defaultTime()));
        $at = \Carbon\CarbonImmutable::now()->startOfDay()->setTime($h, $mi);

        return [
            'total' => $total,
            'not_ordered' => max(0, $total - $ordered),
            'deadline_at' => $at->toIso8601String(),
            'deadline_time' => $service->defaultTime(),
            'open' => \Carbon\CarbonImmutable::now()->lessThanOrEqualTo($at),
        ];
    }

    private function deadlineFor(Store $store): array
    {
        $service = app(SubmissionDeadline::class);
        $d = $service->for($store);

        return [
            'time' => $d['time'],                              // HH:MM
            'source' => $d['source'],                          // date | store | default
            'at' => $d['at']->toIso8601String(),
            'open' => $store->is_active && $service->isOpen($store),
            'store_active' => $store->is_active,
            // has this store already placed (or posted) today's order? Then no reminder is needed.
            'ordered_today' => \App\Models\Order::where('store_id', $store->id)->whereIn('status', \App\Models\Order::ACTIVE)->where('order_date', today()->toDateString())->exists(),
            'timezone' => config('app.timezone'),
        ];
    }
}
