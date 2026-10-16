<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forecast": what a store will probably order on one future day, worked out from what it ordered in a past date range.
 * The app has no stock counts, so the past is the store's own placed orders; the store enters what it has on hand on the
 * page, and the suggested order is the forecast (plus a safety buffer) minus that.
 *
 * A day is forecast from the same weekday in the history (Mondays from Mondays) when the range holds at least two of
 * them, otherwise from the average over all days in the range.
 */
class ForecastController extends Controller
{
    private const MAX_DAYS = 366;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->isStore() || $user->isAreaManager() || $user->isOpic(), 403, 'Only stores, area managers and OPIC have a forecast.');

        $stores = $user->isOpic()
            ? Store::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
            : ($user->isAreaManager()
                ? Store::whereIn('id', $user->visibleStoreIds() ?? [])->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
                : Store::whereKey($user->store_id)->get(['id', 'code', 'name']));

        // OPIC defaults to every store added together (the total it has to be ready to supply); picking one store narrows it
        $store = $stores->firstWhere('id', (int) $request->query('store')) ?? ($user->isOpic() ? null : $stores->first());

        $today = CarbonImmutable::today();
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'target' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'in:'.implode(',', Item::SOURCES)],
        ]);

        $to = CarbonImmutable::parse($data['to'] ?? $today);
        $from = CarbonImmutable::parse($data['from'] ?? $to->subDays(27));
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }
        $target = CarbonImmutable::parse($data['target'] ?? $today->addDay());
        $source = $data['source'] ?? null;

        $items = [];
        if ($store || $user->isOpic()) {
            $items = $this->forecast($store?->id, $from, $to, $target, $source);
        }

        return Inertia::render('forecast', [
            'stores' => $stores,
            'store_id' => $store?->id,
            'for_store' => $user->isAreaManager() || $user->isOpic(),
            'all_stores' => $user->isOpic(),
            'store_count' => $user->isOpic() ? $stores->count() : null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'target' => $target->toDateString(),
            'target_day' => $target->format('l'),
            'source' => $source,
            'days' => $from->diffInDays($to) + 1,
            'same_weekday_days' => $this->countWeekday($from, $to, $target->dayOfWeek),
            'items' => $items,
        ]);
    }

    private function forecast(?int $storeId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $target, ?string $source): array
    {
        $days = $from->diffInDays($to) + 1;
        $sameDays = $this->countWeekday($from, $to, $target->dayOfWeek);

        $rows = DB::table('daily_item_totals')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId)) // no store = every store's totals added together
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->when($source, fn ($q) => $q->where('source', $source))
            ->get(['order_date', 'product_code', 'source', 'units']);

        $total = [];
        $weekday = [];
        foreach ($rows as $r) {
            $key = $r->source.'|'.$r->product_code;
            $total[$key] = ($total[$key] ?? 0) + (int) $r->units;
            if (CarbonImmutable::parse($r->order_date)->dayOfWeek === $target->dayOfWeek) {
                $weekday[$key] = ($weekday[$key] ?? 0) + (int) $r->units;
            }
        }

        // only what is still on the catalog can be ordered
        $catalog = Item::active()->whereIn('product_code', collect(array_keys($total))->map(fn ($k) => explode('|', $k, 2)[1])->unique()->values())
            ->get(['source', 'product_code', 'description', 'category', 'moq'])->keyBy(fn ($i) => $i->source.'|'.$i->product_code);

        $out = [];
        foreach ($total as $key => $units) {
            $item = $catalog[$key] ?? null;
            if (! $item) {
                continue;
            }
            $perDay = $units / $days;
            $forecast = $sameDays >= 2 ? ($weekday[$key] ?? 0) / $sameDays : $perDay;

            $out[] = [
                'code' => $item->product_code,
                'source' => $item->source,
                'name' => $item->description,
                'category' => $item->category,
                'moq' => $item->moq,
                'history_units' => $units,
                'avg_per_day' => round($perDay, 2),
                'forecast' => round($forecast, 2),
            ];
        }

        usort($out, fn ($a, $b) => [$b['forecast'], $b['history_units']] <=> [$a['forecast'], $a['history_units']]);

        return $out;
    }

    private function countWeekday(CarbonImmutable $from, CarbonImmutable $to, int $dow): int
    {
        $n = 0;
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            if ($d->dayOfWeek === $dow) {
                $n++;
            }
        }

        return $n;
    }
}
