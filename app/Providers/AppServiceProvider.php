<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderSummary;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->keepOrderSummaryCurrent();
    }

    /**
     * daily_item_totals (what the dashboard and trends add up) must follow the orders. A bucket is one store's day.
     *  - an order enters or leaves the counted statuses (placed, reset to a draft, cancelled) or changes day/store: rebuild its bucket(s);
     *  - a line is added, changed or removed on a counted order: rebuild its bucket.
     * Code that replaces all of an order's lines at once (placing an order) pauses this with OrderSummary::withoutRefresh()
     * and calls OrderSummary::refreshOrder() once instead.
     */
    private function keepOrderSummaryCurrent(): void
    {
        Order::saved(function (Order $order) {
            if (OrderSummary::isPaused()) {
                return;
            }

            $wasCounted = in_array($order->getOriginal('status'), Order::ACTIVE, true) && ! $order->wasRecentlyCreated;
            $isCounted = in_array($order->status, Order::ACTIVE, true);
            $moved = $order->wasChanged(['order_date', 'store_id']) && ! $order->wasRecentlyCreated;

            if (! $isCounted && ! $wasCounted) {
                return;
            }
            if ($isCounted && $wasCounted && ! $moved) {
                return; // pending -> posted: nothing to add up differently
            }

            if ($moved && $wasCounted) {
                OrderSummary::refreshBucket($order->getOriginal('store_id'), (string) $order->getRawOriginal('order_date'));
            }
            OrderSummary::refreshOrder($order);
        });

        $line = function (OrderItem $item) {
            if (OrderSummary::isPaused()) {
                return;
            }

            $order = Order::find($item->order_id);
            if ($order && in_array($order->status, Order::ACTIVE, true)) {
                OrderSummary::refreshOrder($order);
            }
        };
        OrderItem::created($line);
        OrderItem::updated($line);
        OrderItem::deleted($line);
    }
}
