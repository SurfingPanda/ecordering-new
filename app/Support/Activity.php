<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;

/**
 * Writes the security / admin activity log shown on the Audit Log page.
 *
 *   Activity::log('user_deactivated', Activity::person($user));
 *
 * It records who did it (the signed-in user) and from which IP. It must never break the action being logged, so a
 * failure to write is reported and swallowed.
 */
class Activity
{
    /** event => [label, group]. The group is the heading in the Audit Log's filter. */
    public const EVENTS = [
        'login' => ['Signed in', 'Sign-ins'],
        'login_failed' => ['Failed sign-in', 'Sign-ins'],
        'login_blocked' => ['Sign-in locked', 'Sign-ins'],
        'login_deactivated' => ['Deactivated login tried', 'Sign-ins'],
        'logout' => ['Signed out', 'Sign-ins'],
        'password_changed' => ['Password changed', 'Passwords'],
        'password_reset_requested' => ['Reset link requested', 'Passwords'],
        'password_reset_completed' => ['Password reset by link', 'Passwords'],
        'password_reset_by_admin' => ['Password reset by admin', 'Passwords'],
        'user_created' => ['Login created', 'Users'],
        'user_deactivated' => ['Login deactivated', 'Users'],
        'user_reactivated' => ['Login reactivated', 'Users'],
        'manager_created' => ['Area manager added', 'Users'],
        'manager_updated' => ['Area manager changed', 'Users'],
        'manager_removed' => ['Area manager removed', 'Users'],
        'store_created' => ['Store added', 'Stores'],
        'store_updated' => ['Store edited', 'Stores'],
        'store_toggled' => ['Store (de)activated', 'Stores'],
        'item_created' => ['Item added', 'Catalog'],
        'item_updated' => ['Item edited', 'Catalog'],
        'items_archived' => ['Items archived', 'Catalog'],
        'items_restored' => ['Items restored', 'Catalog'],
        'rule_changed' => ['Sync rule changed', 'Catalog'],
        'category_changed' => ['Category changed', 'Catalog'],
        'ecpos_sync' => ['ECPOS sync run', 'Catalog'],
        'settings_changed' => ['Order setting changed', 'Catalog'],
    ];

    public static function log(string $event, ?string $subject = null, ?string $note = null, ?User $actor = null, ?int $storeId = null): void
    {
        try {
            $request = request();
            ActivityLog::create([
                'user_id' => ($actor ?? $request?->user())?->id,
                'store_id' => $storeId,
                'event' => $event,
                'subject' => $subject === null ? null : mb_substr($subject, 0, 255),
                'note' => $note === null ? null : mb_substr($note, 0, 1000),
                'ip' => $request?->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** "Maria Cruz (maria@bw.test)" */
    public static function person(User $user): string
    {
        return "{$user->name} ({$user->email})";
    }

    public static function store(Store $store): string
    {
        return "{$store->code} — {$store->name}";
    }
}
