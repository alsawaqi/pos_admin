<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ported verbatim from the charity app (shared infra). Talks to the
 * scalefusion v3 devices API and exposes a kiosk_id-keyed summary map
 * of live device telemetry, cached briefly. The POS joins this against
 * pos_devices.kiosk_id only (no charity device records involved).
 */
class ScalefusionService
{
    private bool $hasSummaryMapMemo = false;

    private array $summaryMapMemo = [];

    /**
     * Build a single summary map from Scalefusion's v3 devices list
     * and cache it briefly so changing telemetry still feels live.
     */
    public function getDevicesSummaryMapCached(int $ttlSeconds = 120, int $maxPages = 25): array
    {
        $ttlSeconds = (int) config('services.scalefusion.summary_ttl_seconds', $ttlSeconds);
        $staleTtlSeconds = (int) config('services.scalefusion.stale_ttl_seconds', 900);
        $lockSeconds = (int) config('services.scalefusion.cache_lock_seconds', 20);
        $maxPages = (int) config('services.scalefusion.max_pages', $maxPages);

        if ($this->hasSummaryMapMemo) {
            return $this->summaryMapMemo;
        }

        $store = $this->cacheStore();
        $keyFresh = 'sf:v3:devices_summary_map';
        $keyStale = 'sf:v3:devices_summary_map_stale';
        $keyLock = 'sf:v3:devices_summary_map_refresh_lock';

        $fresh = $store->get($keyFresh);
        if (is_array($fresh) && ! empty($fresh)) {
            return $this->rememberSummaryMap($fresh);
        }

        $stale = $store->get($keyStale);
        $stale = is_array($stale) ? $stale : [];

        $lock = $this->cacheLock($keyLock, $lockSeconds);

        if (! empty($stale)) {
            if ($lock && $lock->get()) {
                try {
                    $refreshed = $this->refreshSummaryMap(
                        $store,
                        $keyFresh,
                        $keyStale,
                        $ttlSeconds,
                        $staleTtlSeconds,
                        $maxPages
                    );

                    if (! empty($refreshed)) {
                        return $refreshed;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Scalefusion stale refresh failed', ['error' => $e->getMessage()]);
                } finally {
                    optional($lock)->release();
                }
            }

            return $this->rememberSummaryMap($stale);
        }

        if ($lock) {
            try {
                $result = $lock->block(3, function () use (
                    $store,
                    $keyFresh,
                    $keyStale,
                    $ttlSeconds,
                    $staleTtlSeconds,
                    $maxPages
                ) {
                    $fresh = $store->get($keyFresh);
                    if (is_array($fresh) && ! empty($fresh)) {
                        return $fresh;
                    }

                    return $this->refreshSummaryMap(
                        $store,
                        $keyFresh,
                        $keyStale,
                        $ttlSeconds,
                        $staleTtlSeconds,
                        $maxPages
                    );
                });

                return $this->rememberSummaryMap(is_array($result) ? $result : []);
            } catch (\Throwable $e) {
                Log::warning('Scalefusion cache lock wait failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            $map = $this->refreshSummaryMap(
                $store,
                $keyFresh,
                $keyStale,
                $ttlSeconds,
                $staleTtlSeconds,
                $maxPages
            );

            if (! empty($map)) {
                return $map;
            }
        } catch (\Throwable $e) {
            Log::warning('Scalefusion summary map build failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Existing API used in other pages.
     * We fulfill it from the cached global summary map
     * and only scan pages when something is missing.
     */
    public function findDevicesByIds(array $ids): array
    {
        $wanted = collect($ids)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        $summary = $this->getDevicesSummaryMapCached();

        $found = [];
        foreach ($wanted as $id) {
            if (isset($summary[$id])) {
                $found[$id] = $summary[$id];
            }
        }

        if (count($found) >= $wanted->count()) {
            return $found;
        }

        $missing = $wanted->filter(fn ($id) => ! isset($found[$id]))->values();
        $cursor = null;
        $guard = 0;
        $perPage = (int) config('services.scalefusion.per_page', 200);

        while ($guard < 10 && $missing->isNotEmpty()) {
            $guard++;
            $json = $this->requestDevicesPage($cursor, $perPage);

            foreach (($json['devices'] ?? []) as $row) {
                $sfId = (string) data_get($row, 'device.id');
                if ($sfId !== '' && $missing->contains($sfId)) {
                    $found[$sfId] = $this->pickImportantFields($row);
                }
            }

            $cursor = $json['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return $found;
    }

    /**
     * Build a full summary map by paging /devices.json.
     */
    protected function buildDevicesSummaryMap(int $maxPages = 25): array
    {
        $map = [];
        $cursor = null;
        $guard = 0;
        $perPage = (int) config('services.scalefusion.per_page', 200);

        while ($guard < $maxPages) {
            $guard++;

            $json = $this->requestDevicesPage($cursor, $perPage);
            if (empty($json)) {
                break;
            }

            foreach (($json['devices'] ?? []) as $row) {
                $sfId = (string) data_get($row, 'device.id');
                if ($sfId !== '') {
                    $map[$sfId] = $this->pickImportantFields($row);
                }
            }

            $cursor = $json['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }

        return $map;
    }

    /**
     * Request a v3 devices list page with backoff for 429.
     */
    protected function requestDevicesPage($cursor = null, int $perPage = 200): array
    {
        $token = config('services.scalefusion.token');
        $base = rtrim(config('services.scalefusion.base_v3'), '/');
        $timeoutSeconds = (int) config('services.scalefusion.http_timeout_seconds', 8);
        $tries = (int) config('services.scalefusion.http_retry_attempts', 3);

        if (! $token || ! $base) {
            return [];
        }

        $params = [];
        if (! empty($cursor)) {
            $params['cursor'] = $cursor;
        }
        if ($perPage > 0) {
            $params['per_page'] = $perPage;
        }

        $sleepUs = 350000;

        try {
            for ($i = 1; $i <= $tries; $i++) {
                $response = Http::timeout($timeoutSeconds)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Authorization' => 'Token '.$token,
                    ])
                    ->get($base.'/devices.json', $params);

                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 0);
                    usleep($retryAfter > 0 ? $retryAfter * 1000000 : $sleepUs);
                    $sleepUs *= 2;

                    continue;
                }

                if (! $response->ok()) {
                    return [];
                }

                return $response->json();
            }
        } catch (ConnectionException $e) {
            Log::warning('Scalefusion unreachable', [
                'url' => $base.'/devices.json',
                'cursor' => $cursor,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (\Throwable $e) {
            Log::warning('Scalefusion error', ['error' => $e->getMessage()]);

            return [];
        }

        return [];
    }

    protected function pickImportantFields(array $row): array
    {
        $device = data_get($row, 'device', []);

        return [
            'id' => data_get($device, 'id'),
            'name' => data_get($device, 'name'),
            'battery_status' => data_get($device, 'battery_status'),
            'battery_charging' => data_get($device, 'battery_charging'),
            'connection_state' => data_get($device, 'connection_state'),
            'connection_status' => data_get($device, 'connection_status'),
            'device_status' => data_get($device, 'device_status'),
            'locked' => data_get($device, 'locked'),
            'last_connected_at' => data_get($device, 'last_connected_at'),
            'last_seen_on' => data_get($device, 'last_seen_on'),
            'ip_address' => data_get($device, 'ip_address'),
            'public_ip' => data_get($device, 'public_ip'),
            'location' => [
                'lat' => data_get($device, 'location.lat'),
                'lng' => data_get($device, 'location.lng'),
                'address' => data_get($device, 'location.address'),
                'date_time' => data_get($device, 'location.date_time'),
            ],
        ];
    }

    protected function refreshSummaryMap($store, string $keyFresh, string $keyStale, int $ttlSeconds, int $staleTtlSeconds, int $maxPages): array
    {
        $map = $this->buildDevicesSummaryMap($maxPages);

        if (! empty($map)) {
            $store->put($keyFresh, $map, now()->addSeconds($ttlSeconds));
            $store->put($keyStale, $map, now()->addSeconds($staleTtlSeconds));

            return $this->rememberSummaryMap($map);
        }

        return [];
    }

    protected function rememberSummaryMap(array $map): array
    {
        $this->hasSummaryMapMemo = true;
        $this->summaryMapMemo = $map;

        return $map;
    }

    protected function cacheStore(): Repository
    {
        $configuredStore = config('services.scalefusion.cache_store', config('cache.default'));

        try {
            return Cache::store($configuredStore);
        } catch (\Throwable $e) {
            Log::warning('Scalefusion cache store fallback engaged', [
                'store' => $configuredStore,
                'error' => $e->getMessage(),
            ]);

            return Cache::store(config('cache.default'));
        }
    }

    protected function cacheLock(string $key, int $seconds): ?Lock
    {
        $repository = $this->cacheStore();
        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            Log::warning('Scalefusion cache store does not support locks', [
                'driver' => $store::class,
            ]);

            return null;
        }

        try {
            return $store->lock($key, $seconds);
        } catch (\Throwable $e) {
            Log::warning('Scalefusion cache lock failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch a single device's full live detail from Scalefusion's v3
     * API: the rich telemetry the device-detail page renders (RAM,
     * storage, CPU/thermals, OS, IMEI, SIM, Wi-Fi, management, ...).
     * Returns the raw v3 payload untouched so the UI can read any field.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function getDevice(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()->get($this->v3('/devices/'.$deviceId.'.json')),
        );
    }

    /**
     * Daily GPS route history for a device (v1). Points are normalised
     * + sorted oldest-first, ready for the map + timeline.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function getDeviceLocations(int|string $deviceId, string $date): array
    {
        $result = $this->callScalefusion(
            fn () => $this->scalefusionClient()->get(
                $this->v1('/devices/'.$deviceId.'/locations.json'),
                ['date' => $date],
            ),
        );

        if (! $result['ok']) {
            return $result;
        }

        $items = collect(is_array($result['data']) ? $result['data'] : [])
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) use ($deviceId): array {
                $dateTime = $row['date_time'] ?? null;

                return [
                    'device_id' => (int) ($row['device_id'] ?? $row['deviceId'] ?? $deviceId),
                    'location_id' => isset($row['location_id']) ? (int) $row['location_id'] : null,
                    'address' => $row['address'] ?? null,
                    'latitude' => isset($row['latitude']) ? (float) $row['latitude'] : null,
                    'longitude' => isset($row['longitude']) ? (float) $row['longitude'] : null,
                    'accuracy' => isset($row['accuracy']) ? (float) $row['accuracy'] : null,
                    'date_time' => is_numeric($dateTime) ? (int) $dateTime : null,
                    'created_at_tz' => $row['created_at_tz'] ?? null,
                ];
            })
            ->filter(fn ($row) => $row['latitude'] !== null && $row['longitude'] !== null)
            ->sortBy(fn ($row) => $row['date_time'] ?? 0)
            ->values()
            ->all();

        return ['ok' => true, 'status' => $result['status'], 'data' => $items];
    }

    /**
     * Reboot a device (v1 PUT, empty JSON body).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function reboot(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put($this->v1('/devices/'.$deviceId.'/reboot.json'), []),
        );
    }

    /**
     * Ring a device's locate alarm (v1 POST, empty JSON body).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendAlarm(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->v1('/devices/'.$deviceId.'/send_alarm.json'), []),
        );
    }

    /**
     * Broadcast an on-screen message to a device.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function broadcastMessage(int|string $deviceId, string $senderName, string $messageBody, bool $keepRinging = true, bool $showAsDialog = true): array
    {
        $body = $this->formBody([
            'device_ids' => [$deviceId],
            'sender_name' => trim($senderName),
            'message_body' => trim($messageBody),
            'keep_ringing' => $keepRinging,
            'show_as_dialog' => $showAsDialog,
        ]);

        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/broadcast_message.json'), ['body' => $body]),
        );
    }

    /**
     * Run a Scalefusion device action. action_type is one of:
     * screen_lock, shutdown, reboot, mark_as_lost, mark_as_found,
     * factory_reset, delete_device, buzz_device, rotate_filevault_key.
     *
     * @param  array<string, mixed>  $options  lost_mode_message|footnote|phone, wipe_sd_card
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function runAction(int|string $deviceId, string $actionType, array $options = []): array
    {
        $bodyFields = [
            'action_type' => $actionType,
            'wipe_sd_card' => (bool) ($options['wipe_sd_card'] ?? false),
        ];

        foreach (['lost_mode_message', 'lost_mode_footnote', 'lost_mode_phone'] as $field) {
            if (filled($options[$field] ?? null)) {
                $bodyFields[$field] = trim((string) $options[$field]);
            }
        }

        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->queryArrayUrl('/devices/actions.json', 'device_ids', [$deviceId]), [
                    'body' => $this->formBody($bodyFields),
                ]),
        );
    }

    /**
     * Clear the managed app's data (Android only).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function clearAppData(int|string $deviceId): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/clear_app_data.json'), [
                    'body' => $this->formArrayBody('device_ids', [$deviceId]),
                ]),
        );
    }

    /**
     * Lock one or more devices into kiosk mode.
     *
     * @param  list<int|string>  $deviceIds
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function lock(array $deviceIds): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/lock.json'), [
                    'body' => $this->formArrayBody('device_ids', $deviceIds),
                ]),
        );
    }

    /**
     * Release one or more devices from kiosk lock.
     *
     * @param  list<int|string>  $deviceIds
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function unlock(array $deviceIds): array
    {
        return $this->callScalefusion(
            fn () => $this->scalefusionClient()
                ->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
                ->send('POST', $this->v1('/devices/unlock.json'), [
                    'body' => $this->formArrayBody('device_ids', $deviceIds),
                ]),
        );
    }

    // --- shared helpers (ported from the charity ScalefusionController) ---

    /**
     * Run a Scalefusion HTTP call + normalise the outcome. Connection
     * failures degrade to ok=false/status=503 instead of throwing, so
     * callers never need their own try/catch.
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $request
     * @return array{ok: bool, status: int, data: mixed}
     */
    protected function callScalefusion(callable $request): array
    {
        if (! config('services.scalefusion.token')) {
            return ['ok' => false, 'status' => 0, 'data' => ['message' => 'Scalefusion is not configured.']];
        }

        try {
            $response = $request();

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json() ?? $response->body(),
            ];
        } catch (ConnectionException $e) {
            Log::warning('Scalefusion unreachable', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 503, 'data' => ['message' => 'Scalefusion unreachable']];
        } catch (\Throwable $e) {
            Log::warning('Scalefusion request failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 0, 'data' => ['message' => 'Scalefusion request failed']];
        }
    }

    protected function scalefusionClient(): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = (int) config('services.scalefusion.http_timeout_seconds', 12);

        return Http::timeout($timeout)
            ->retry(2, 250, null, false)
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Token '.config('services.scalefusion.token'),
            ]);
    }

    protected function v3(string $path): string
    {
        return rtrim((string) config('services.scalefusion.base_v3'), '/').$path;
    }

    protected function v1(string $path): string
    {
        return rtrim((string) config('services.scalefusion.base_v1'), '/').$path;
    }

    /**
     * @param  list<int|string>  $values
     */
    protected function formArrayBody(string $key, array $values): string
    {
        return $this->formBody([$key => $values]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function formBody(array $fields): string
    {
        $pairs = [];

        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = [$key.'[]', $item];
                }

                continue;
            }

            $pairs[] = [$key, $value];
        }

        return collect($pairs)
            ->map(fn (array $field) => rawurlencode($field[0]).'='.rawurlencode($this->formValue($field[1])))
            ->implode('&');
    }

    protected function formValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param  list<int|string>  $values
     */
    protected function queryArrayUrl(string $path, string $key, array $values): string
    {
        $query = collect($values)
            ->map(fn ($value) => rawurlencode($key.'[]').'='.rawurlencode((string) $value))
            ->implode('&');

        return $query === '' ? $this->v1($path) : $this->v1($path).'?'.$query;
    }
}
