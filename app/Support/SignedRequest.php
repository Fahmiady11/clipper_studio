<?php

namespace App\Support;

/**
 * Meta's signed_request: "<signature>.<payload>", both base64url, the signature
 * an HMAC-SHA256 of the encoded payload with the app secret. Facebook's data
 * deletion and deauthorize callbacks send it, and so do the Threads uninstall
 * and delete callbacks (signed with the Threads app secret).
 * https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
 */
final class SignedRequest
{
    /** The decoded payload, or null when it is malformed, unsigned by this secret or not HMAC-SHA256. */
    public static function parse(?string $signedRequest, ?string $secret): ?array
    {
        if (! $signedRequest || ! $secret || ! str_contains($signedRequest, '.')) {
            return null;
        }

        [$encodedSig, $encodedPayload] = explode('.', $signedRequest, 2);
        $signature = self::decode($encodedSig);
        $payload = json_decode((string) self::decode($encodedPayload), true);

        if ($signature === null || ! is_array($payload)) {
            return null;
        }
        if (strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }
        if (! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            return null;
        }

        return $payload;
    }

    /** Build one, the way Meta does. For tests and local checks of the callbacks. */
    public static function make(array $payload, string $secret): string
    {
        $encodedPayload = self::encode(json_encode($payload + ['algorithm' => 'HMAC-SHA256']));

        return self::encode(hash_hmac('sha256', $encodedPayload, $secret, true)).'.'.$encodedPayload;
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
