<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\ValueObjects\Auth\IssuedJwt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class JwtTokenService
{
    public function issueFor(User $user): IssuedJwt
    {
        $issuedAt = Carbon::now();
        $expiresAt = $issuedAt->copy()->addMinutes((int) config('pos_admin_auth.jwt.ttl_minutes'));

        $payload = [
            'iss' => (string) config('pos_admin_auth.jwt.issuer'),
            'aud' => (string) config('pos_admin_auth.jwt.audience'),
            'sub' => (string) $user->getKey(),
            'iat' => $issuedAt->getTimestamp(),
            'nbf' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'jti' => (string) Str::uuid(),
            'guard' => 'web',
        ];

        return new IssuedJwt(
            accessToken: $this->encode($payload),
            expiresAt: $expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        [$encodedHeader, $encodedPayload, $encodedSignature] = $this->segments($token);

        $header = $this->decodeJson($encodedHeader);

        if (($header['typ'] ?? null) !== 'JWT' || ($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported JWT header.');
        }

        $expectedSignature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->signingKey(), true);

        if (! hash_equals($expectedSignature, $this->base64UrlDecode($encodedSignature))) {
            throw new RuntimeException('JWT signature is invalid.');
        }

        $payload = $this->decodeJson($encodedPayload);
        $now = now()->timestamp;

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > $now) {
            throw new RuntimeException('JWT cannot be used yet.');
        }

        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] <= $now) {
            throw new RuntimeException('JWT has expired.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function encode(array $payload): string
    {
        $encodedHeader = $this->base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ], JSON_THROW_ON_ERROR));

        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->signingKey(), true);

        return $encodedHeader.'.'.$encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function segments(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new RuntimeException('JWT must contain three segments.');
        }

        return [$segments[0], $segments[1], $segments[2]];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeJson(string $encodedValue): array
    {
        $decoded = json_decode($this->base64UrlDecode($encodedValue), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('JWT segment must decode to an object.');
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('JWT segment is not valid base64url.');
        }

        return $decoded;
    }

    private function signingKey(): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY must be configured before issuing POS admin JWTs.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('APP_KEY is not valid base64.');
            }

            return $decoded;
        }

        return $appKey;
    }
}
