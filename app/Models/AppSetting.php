<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    public static function read(string $key, ?string $default = null): ?string
    {
        return static::query()->whereKey($key)->value('value') ?? $default;
    }

    public static function write(string $key, string $value, ?int $userId = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
