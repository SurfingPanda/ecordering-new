<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Area managers: accounts that see the orders and reports of the stores an admin gives them (and can act on those
 * orders), and nothing else. Only admins manage them.
 */
class AreaManagerController extends Controller
{
    public function index(): Response
    {
        $managers = User::where('role', User::AREA_MANAGER)->with('managedStores:id,code,name')->orderBy('name')->get();

        return Inertia::render('admin/area-managers', [
            'managers' => $managers->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'active' => $m->isActive(),
                'stores' => $m->managedStores->sortBy('name')->map(fn (Store $s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values(),
            ]),
            'stores' => Store::orderBy('name')->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ] + $this->storeRules(), $this->messages());

        $manager = DB::transaction(function () use ($data) {
            $manager = new User(['name' => trim($data['name']), 'email' => Str::lower(trim($data['email'])), 'password' => $data['password']]);
            $manager->forceFill(['role' => User::AREA_MANAGER, 'store_id' => null])->save(); // role is never mass-assignable
            $manager->managedStores()->sync($data['stores']);
            Activity::log('manager_created', Activity::person($manager), count($data['stores']).' store(s)');

            return $manager;
        });

        return back()->with('success', "{$manager->name} can now sign in as an area manager for ".count($data['stores']).' '.(count($data['stores']) === 1 ? 'store' : 'stores').'. Share the password with them.');
    }

    public function update(Request $request, User $manager): RedirectResponse
    {
        $this->mustBeAreaManager($manager);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($manager->id)],
        ] + $this->storeRules(), $this->messages());

        DB::transaction(function () use ($manager, $data) {
            $manager->forceFill(['name' => trim($data['name']), 'email' => Str::lower(trim($data['email']))])->save();
            $manager->managedStores()->sync($data['stores']);
            Activity::log('manager_updated', Activity::person($manager), count($data['stores']).' store(s)');
        });

        return back()->with('success', "{$manager->name} now handles ".count($data['stores']).' '.(count($data['stores']) === 1 ? 'store' : 'stores').'.');
    }

    public function password(Request $request, User $manager): RedirectResponse
    {
        $this->mustBeAreaManager($manager);

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']], ['password.confirmed' => 'The two passwords do not match.']);
        $manager->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
        Activity::log('password_reset_by_admin', Activity::person($manager));

        return back()->with('success', "Password reset for {$manager->name}. Share the new password with them.");
    }

    /** Removes the account (and signs it out). Orders they cancelled keep their history; it just no longer names them. */
    public function destroy(User $manager): RedirectResponse
    {
        $this->mustBeAreaManager($manager);

        Activity::log('manager_removed', Activity::person($manager));
        DB::transaction(function () use ($manager) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $manager->id)->delete();
            $manager->delete();
        });

        return back()->with('success', "{$manager->name}'s area manager account was removed.");
    }

    /** Switches an area manager off or on. Off = signed out at once and unable to sign in, but the account and its stores are kept. */
    public function status(Request $request, User $manager): RedirectResponse
    {
        $this->mustBeAreaManager($manager);

        $data = $request->validate(['active' => ['required', 'boolean']]);
        $manager->setActive((bool) $data['active']);
        Activity::log($data['active'] ? 'user_reactivated' : 'user_deactivated', Activity::person($manager));

        return back()->with('success', $data['active'] ? "{$manager->name} can sign in again." : "{$manager->name} was deactivated and signed out.");
    }

    /** Only area manager accounts: this page can never touch an admin or a store login. */
    private function mustBeAreaManager(User $user): void
    {
        abort_unless($user->role === User::AREA_MANAGER, 404);
    }

    private function storeRules(): array
    {
        return [
            'stores' => ['required', 'array', 'min:1'],
            'stores.*' => ['integer', 'distinct', Rule::exists('stores', 'id')],
        ];
    }

    private function messages(): array
    {
        return ['stores.required' => 'Pick at least one store for this area manager.', 'stores.min' => 'Pick at least one store for this area manager.', 'email.unique' => 'That email already has a login.'];
    }
}
