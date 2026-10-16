<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    // role and store_id are deliberately NOT mass-assignable: they are only ever set explicitly by the
    // server, so a request can't promote an account or move it to another store.

    /** The reset email is our own branded one, sent straight away. A mail problem is logged, never shown (the form answers the same either way). */
    public function sendPasswordResetNotification($token): void
    {
        try {
            \Illuminate\Support\Facades\Mail::to($this->email)->send(new \App\Mail\ResetPasswordMail(
                $this->name,
                url(route('password.reset', ['token' => $token, 'email' => $this->email], false)),
                (int) config('auth.passwords.users.expire'),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public const ADMIN = 'admin';

    public const STORE = 'store';

    public const AREA_MANAGER = 'area_manager';

    /** OPIC (operations/purchasing): sees every store's orders and the combined forecast, and can cancel a posted TR. Never orders, never touches ECPOS or settings. */
    public const OPIC = 'opic';

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** A deactivated login can't sign in and is signed out of everything; its history stays. */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** Switches the login off or on. Switching off also ends every session and any "keep me signed in" cookie. */
    public function setActive(bool $active): void
    {
        $this->forceFill(['deactivated_at' => $active ? null : now()] + ($active ? [] : ['remember_token' => \Illuminate\Support\Str::random(60)]))->save();

        if (! $active && config('session.driver') === 'database') {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        }
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function isStore(): bool
    {
        return $this->role === self::STORE;
    }

    public function isAreaManager(): bool
    {
        return $this->role === self::AREA_MANAGER;
    }

    public function isOpic(): bool
    {
        return $this->role === self::OPIC;
    }

    /** Admins, area managers and OPIC: they look across stores (a manager only across the stores given to them) and can cancel a posted TR. */
    public function isManagement(): bool
    {
        return $this->isAdmin() || $this->isAreaManager() || $this->isOpic();
    }

    /** ECPOS details, resending and the ECPOS queue stay with admins and area managers; OPIC doesn't see them. */
    public function canManageEcpos(): bool
    {
        return $this->isAdmin() || $this->isAreaManager();
    }

    /** The stores an area manager handles, chosen by an admin. */
    public function managedStores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'area_manager_stores')->withTimestamps();
    }

    private ?array $visibleStoreIds = null;

    /**
     * The stores whose orders and reports this person may see. NULL means every store (admins, OPIC); otherwise the list:
     * a store account's own store, or the stores an area manager was given. Everything that reads orders or reports
     * goes through this, so a manager can never see a store that wasn't given to them.
     *
     * @return list<int>|null
     */
    public function visibleStoreIds(): ?array
    {
        if ($this->isAdmin() || $this->isOpic()) {
            return null;
        }

        return $this->visibleStoreIds ??= $this->isAreaManager()
            ? $this->managedStores()->pluck('stores.id')->map(fn ($id) => (int) $id)->all()
            : [(int) ($this->store_id ?? 0)];
    }}
