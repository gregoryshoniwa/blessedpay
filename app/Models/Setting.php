<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/** A value staff set in the console. Read with Setting::read(), written with Setting::write(). */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Read once per request. */
    private static array $loaded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::$loaded)) {
            try {
                self::$loaded[$key] = self::find($key)?->value;
            } catch (Throwable) {
                self::$loaded[$key] = null; // before the table exists (a fresh install mid-migration)
            }
        }

        return self::$loaded[$key] ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$loaded[$key] = $value;
    }

    public static function forget(): void
    {
        self::$loaded = [];
    }
}
