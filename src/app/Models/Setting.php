<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Platform-level configuration row.
 *
 * Use the static {@see self::get()} / {@see self::set()} helpers
 * everywhere rather than Eloquent directly — they handle the
 * Redis cache layer so hot-path callers (every request reads at
 * least `general.support_email` for the layout) don't issue a
 * SELECT per call.
 *
 * Cache strategy:
 *   - Each key is cached individually under a "pos_settings:{key}"
 *     namespace with no TTL (busted explicitly on write).
 *   - On write we forget the key; the next get() repopulates.
 *   - The cache namespace is prefixed by config('cache.prefix')
 *     so the pos_admin + pos_merchant + charity apps don't
 *     collide (each has its own CACHE_PREFIX).
 *
 * Why no TTL: settings change rarely (manual admin edits) and
 * always via {@see self::set()}, which busts the cache. A TTL
 * would mostly just churn the cache for no benefit.
 */
class Setting extends Model
{
    /** Type tags used by the UI to pick an input component. */
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_EMAIL_LIST = 'email_list';

    protected $table = 'pos_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group_key',
        'label_en',
        'label_ar',
        'help_en',
        'help_ar',
        'options',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',     // JSONB / JSON column → PHP value
            'options' => 'array',
            'display_order' => 'integer',
        ];
    }

    /**
     * Read a setting's value, using the cache when available.
     * Returns $default when the key isn't seeded — callers should
     * always pass a sensible default that matches a clean
     * environment (an upstream `.env` value, for instance).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(self::cacheKeyFor($key), static function () use ($key, $default) {
            $row = self::query()->where('key', $key)->first();
            if ($row === null) {
                return $default;
            }

            // value is already array-casted by Eloquent. Pull out
            // the [value] sub-field if we wrapped a scalar to keep
            // jsonb happy, otherwise return the array as-is.
            $raw = $row->getAttributes()['value'] ?? null;
            $decoded = $raw === null ? null : json_decode((string) $raw, true);

            // We support two storage shapes:
            //   - scalar values stored as `{"v": ...}` (because some
            //     drivers reject top-level scalars in JSON columns)
            //   - object/array values stored directly
            if (is_array($decoded) && array_key_exists('v', $decoded) && count($decoded) === 1) {
                return $decoded['v'];
            }
            return $decoded ?? $default;
        });
    }

    /**
     * Write a setting's value. Wraps scalars in `{"v": ...}` for
     * driver portability + busts the cache so the next get() reads
     * fresh.
     */
    public static function set(string $key, mixed $value): void
    {
        $stored = match (true) {
            is_array($value) => $value,
            default => ['v' => $value],
        };

        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored],
        );

        Cache::forget(self::cacheKeyFor($key));
    }

    /**
     * Forget every cached setting in one shot — used by the
     * settings controller after a bulk update + by tests during
     * setUp to avoid leakage between examples.
     */
    public static function forgetAllCached(): void
    {
        foreach (self::query()->pluck('key') as $key) {
            Cache::forget(self::cacheKeyFor($key));
        }
    }

    private static function cacheKeyFor(string $key): string
    {
        // Underscore prefix matches the existing convention
        // ("pos_admin_cache" CACHE_PREFIX in .env).
        return 'pos_settings:'.$key;
    }
}
