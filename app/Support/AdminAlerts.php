<?php

namespace App\Support;

use App\Mail\EcposSendFailed;
use App\Mail\OrderPosted;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the people who look after an order about it: the admins on the list in .env, plus the area managers who
 * handle that order's store. Email is a courtesy on top of what the app already shows, so a mail problem is reported
 * to the log and never stops an order from being posted or a send from being recorded.
 */
class AdminAlerts
{
    public static function orderPosted(Order $order): void
    {
        if (config('notifications.order_posted')) {
            self::send(new OrderPosted($order->id), self::recipients($order));
        }
    }

    public static function ecposFailed(Order $order): void
    {
        if (config('notifications.ecpos_failed')) {
            self::send(new EcposSendFailed($order->id), self::recipients($order));
        }
    }

    /** @return list<string> admin list + the area managers of the order's store, each address once */
    public static function recipients(Order $order): array
    {
        $managers = User::where('role', User::AREA_MANAGER)
            ->whereHas('managedStores', fn ($q) => $q->where('stores.id', $order->store_id))
            ->pluck('email')->all();

        $all = array_merge(config('notifications.admin_emails'), $managers);

        // one email per person even if they are on both lists (compared without regard to capital letters)
        return array_values(array_intersect_key($all, array_unique(array_map('strtolower', $all))));
    }

    private static function send(object $mail, array $to): void
    {
        if ($to === []) {
            return;
        }

        try {
            Mail::to($to)->send($mail); // the mail itself is queued (ShouldQueue), so a slow mail server never holds anyone up
        } catch (Throwable $e) {
            report($e);
        }
    }
}
