<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\DeadlineChange;
use App\Models\Store;
use App\Models\StoreDeadline;
use App\Models\User;
use App\Services\SubmissionDeadline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionSettingsController extends Controller
{
    public function show(SubmissionDeadline $deadlines): Response
    {
        $today = today();
        $default = AppSetting::find('default_deadline');

        return Inertia::render('admin/settings', [
            'timezone' => config('app.timezone'),
            'today' => $today->toDateString(),
            'default' => [
                'time' => $deadlines->defaultTime(),
                'updated_at' => $default?->updated_by ? $default->updated_at?->toIso8601String() : null,
                'updated_by' => $default?->updated_by ? User::whereKey($default->updated_by)->value('name') : null,
            ],
            'one_order_per_day' => AppSetting::read('one_order_per_day', '1') === '1',
            'stores' => Store::orderBy('name')->get()->map(function (Store $s) use ($deadlines, $today) {
                $effective = $deadlines->for($s, $today);

                return [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'is_active' => $s->is_active,
                    'effective_time' => $effective['time'],
                    'source' => $effective['source'], // date | store | default
                    'recurring' => $s->deadlines()->whereNull('date')->first()?->only('id', 'time'),
                ];
            }),
            'overrides' => StoreDeadline::with('store:id,code,name')
                ->whereNotNull('date')->whereDate('date', '>=', $today)
                ->orderBy('date')->orderBy('store_id')->get()
                ->map(fn (StoreDeadline $d) => ['id' => $d->id, 'date' => $d->date, 'time' => $d->time, 'store' => $d->store->only('id', 'code', 'name')]),
            'history' => DeadlineChange::with(['store:id,code,name', 'changedBy:id,name'])
                ->orderByDesc('created_at')->orderByDesc('id')->limit(25)->get()
                ->map(fn (DeadlineChange $c) => [
                    'id' => $c->id,
                    'scope' => $c->store ? $c->store->code.' — '.$c->store->name : 'All stores (default)',
                    'applies_on' => $c->applies_on,
                    'previous' => $c->previous_time,
                    'new' => $c->new_time,
                    'by' => $c->changedBy?->name,
                    'at' => $c->created_at->toIso8601String(),
                ]),
        ]);
    }

    /** The system-wide default deadline. */
    public function updateDefault(Request $request, SubmissionDeadline $deadlines): RedirectResponse
    {
        $data = $request->validate(['time' => ['required', 'date_format:H:i']]);
        $previous = $deadlines->defaultTime();

        if ($previous !== $data['time']) {
            DB::transaction(function () use ($data, $previous, $request) {
                AppSetting::write('default_deadline', $data['time'], $request->user()->id);
                DeadlineChange::create(['store_id' => null, 'previous_time' => $previous, 'new_time' => $data['time'], 'changed_by' => $request->user()->id]);
            });
        }

        return back()->with('success', 'Default deadline updated.');
    }

    /** A store-specific deadline: recurring (no date) or for one calendar day. */
    public function saveStoreDeadline(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.today()->toDateString()],
            'time' => ['required', 'date_format:H:i'],
        ], ['date.after_or_equal' => 'Pick today or a future date.']);

        DB::transaction(function () use ($data, $request) {
            $existing = StoreDeadline::where('store_id', $data['store_id'])
                ->when($data['date'] ?? null, fn ($q, $d) => $q->whereDate('date', $d), fn ($q) => $q->whereNull('date'))
                ->first();

            if ($existing?->time === $data['time']) {
                return;
            }

            $previous = $existing?->time;
            $row = $existing ?? new StoreDeadline(['store_id' => $data['store_id'], 'date' => $data['date'] ?? null]);
            $row->fill(['time' => $data['time'], 'updated_by' => $request->user()->id])->save();

            DeadlineChange::create([
                'store_id' => $data['store_id'],
                'applies_on' => $data['date'] ?? null,
                'previous_time' => $previous,
                'new_time' => $data['time'],
                'changed_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Deadline saved.');
    }

    /** Removes a store-specific deadline so the store falls back to the default. */
    public function deleteStoreDeadline(Request $request, StoreDeadline $deadline): RedirectResponse
    {
        DB::transaction(function () use ($deadline, $request) {
            DeadlineChange::create([
                'store_id' => $deadline->store_id,
                'applies_on' => $deadline->date,
                'previous_time' => $deadline->time,
                'new_time' => null,
                'changed_by' => $request->user()->id,
            ]);
            $deadline->delete();
        });

        return back()->with('success', 'Deadline removed. The default now applies.');
    }

    public function updateOrderRule(Request $request): RedirectResponse
    {
        $data = $request->validate(['one_order_per_day' => ['required', 'boolean']]);
        AppSetting::write('one_order_per_day', $data['one_order_per_day'] ? '1' : '0', $request->user()->id);

        return back()->with('success', 'Order rule updated.');
    }
}
