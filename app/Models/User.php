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
            'password' => 'hashed',
        ];
    }

    public const ADMIN = 'admin';

    public const STORE = 'store';

    public const AREA_MANAGER = 'area_manager';

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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

    /** Admins and area managers: they look across stores (a manager only across the stores given to them). */
    public function isManagement(): bool
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
     * The stores whose orders and reports this person may see. NULL means every store (admins); otherwise the list:
     * a store account's own store, or the stores an area manager was given. Everything that reads orders or reports
     * goes through this, so a manager can never see a store that wasn't given to them.
     *
     * @return list<int>|null
     */
    public function visibleStoreIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        return $this->visibleStoreIds ??= $this->isAreaManager()
            ? $this->managedStores()->pluck('stores.id')->map(fn ($id) => (int) $id)->all()
            : [(int) ($this->store_id ?? 0)];
    }}
