<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\SubmissionDeadline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null;

        $stores = Store::query()
            ->withCount(['users', 'orders as active_orders_count' => fn ($q) => $q->whereIn('status', Order::ACTIVE)])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Store $s) => $this->row($s));

        return Inertia::render('admin/stores/index', [
            'ecpos' => ['configured' => (bool) config('ecpos.api_key'), 'last_sync' => \App\Models\AppSetting::read('ecpos_stores_last_sync')],
            'stores' => $stores,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function show(Store $store, SubmissionDeadline $deadlines): Response
    {
        $d = $deadlines->for($store);

        return Inertia::render('admin/stores/show', [
            'store' => $this->row($store),
            'users' => $store->users()->orderBy('name')->get(['id', 'name', 'email', 'created_at']),
            // NOT called 'deadline': that name is the shared prop that drives the store-account banner
            'store_deadline' => ['time' => $d['time'], 'source' => $d['source']],
            'counts' => [
                'pending' => $store->orders()->where('status', 'pending')->count(),
                'posted' => $store->orders()->where('status', 'posted')->count(),
                'cancelled' => $store->orders()->where('status', 'cancelled')->count(),
            ],
            'recent' => $store->orders()->where('status', '!=', Order::DRAFT)->orderByDesc('order_date')->orderByDesc('id')->limit(8)->get()
                ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number(), 'status' => $o->status, 'order_date' => $o->order_date->toDateString()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalise($request);

        $data = $request->validate([
            ...$this->storeRules(),
            // optional login for the store, created together with it
            'user_name' => ['nullable', 'required_with:user_email,user_password', 'string', 'max:255'],
            'user_email' => ['nullable', 'required_with:user_name,user_password', 'email:rfc', 'max:255', 'unique:users,email'],
            'user_password' => ['nullable', 'required_with:user_name,user_email', 'string', 'min:8', 'max:255'],
        ]);

        $store = DB::transaction(function () use ($data) {
            $store = Store::create($this->storeAttributes($data));

            if (! empty($data['user_email'])) {
                $this->createStoreUser($store, $data['user_name'], $data['user_email'], $data['user_password']);
            }

            return $store;
        });

        return to_route('admin.stores.show', $store)->with('success', "{$store->name} was added.");
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->normalise($request);

        $data = $request->validate($this->storeRules($store));
        $store->update($this->storeAttributes($data));

        return back()->with('success', "{$store->name} was updated.");
    }

    /** Activate / deactivate. Orders and history are kept; a deactivated store just can't submit new orders. */
    public function toggle(Store $store): RedirectResponse
    {
        $store->update(['is_active' => ! $store->is_active]);

        return back()->with('success', $store->is_active
            ? "{$store->name} is active again."
            : "{$store->name} was deactivated. It can no longer submit orders; its history is unchanged.");
    }

    public function addUser(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $this->createStoreUser($store, $data['name'], $data['email'], $data['password']);

        return back()->with('success', "Login created for {$data['name']}.");
    }

    public function resetPassword(Request $request, Store $store, User $user): RedirectResponse
    {
        // only logins that belong to this store, and never an admin account
        abort_unless($user->role === 'store' && $user->store_id === $store->id, 404);

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']]);

        $user->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();

        return back()->with('success', "Password reset for {$user->name}. Share the new password with them.");
    }

    /** Codes are stored upper-case, so compare them upper-case too (otherwise 'sf01' would slip past 'SF01'). */
    private function normalise(Request $request): void
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);
    }

    private function storeRules(?Store $store = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('stores', 'name')->ignore($store)],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('stores', 'code')->ignore($store)],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.-]{5,40}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'is_active' => ['required', 'boolean'],
            // the store's ID in ECPOS (optional); each ECPOS store can be linked to one store only
            'ecpos_store_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('stores', 'ecpos_store_id')->ignore($store)],
        ];
    }

    private function storeAttributes(array $data): array
    {
        $attrs = collect($data)->only(['name', 'code', 'address', 'contact_person', 'contact_number', 'email', 'is_active'])->all();
        $attrs['code'] = strtoupper($attrs['code']);
        $ecpos = strtoupper(trim((string) ($data['ecpos_store_id'] ?? '')));
        $attrs['ecpos_store_id'] = $ecpos === '' ? null : $ecpos;

        return $attrs;
    }

    /** role and store_id are set here, on the server, and never read from the request. */
    private function createStoreUser(Store $store, string $name, string $email, string $password): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]); // 'hashed' cast
        $user->forceFill(['role' => User::STORE, 'store_id' => $store->id])->save();

        return $user;
    }

    private function row(Store $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'ecpos_store_id' => $s->ecpos_store_id,
            'name' => $s->name,
            'address' => $s->address,
            'contact_person' => $s->contact_person,
            'contact_number' => $s->contact_number,
            'email' => $s->email,
            'is_active' => $s->is_active,
            'users_count' => $s->users_count ?? $s->users()->count(),
            'active_orders_count' => $s->active_orders_count ?? $s->orders()->whereIn('status', Order::ACTIVE)->count(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
