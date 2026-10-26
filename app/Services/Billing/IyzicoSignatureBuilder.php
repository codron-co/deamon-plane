<?php

namespace App\Services\Billing;

/**
 * IYZWSv2 request authorization header and HMAC-SHA256 response signature,
 * ported from the Deamon CMS gateway (proven in production):
 * https://docs.iyzico.com/en/getting-started/preliminaries/authentication/hmacsha256-auth
 */
final class IyzicoSignatureBuilder
{
    /**
     * The exact JSON body that is signed and sent.
     *
     * @param  array<string, mixed>  $requestBody
     */
    public function encode(array $requestBody): string
    {
        return $requestBody === [] ? '' : (string) json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{Authorization: string, x-iyzi-rnd: string}
     */
    public function headers(string $apiKey, string $secretKey, string $uriPath, string $jsonBody): array
    {
        $randomKey = $this->randomKey();
        $signature = hash_hmac('sha256', $randomKey.$uriPath.$jsonBody, $secretKey);

        $authorizationString = 'apiKey:'.$apiKey
            .'&randomKey:'.$randomKey
            .'&signature:'.$signature;

        return [
            'Authorization' => 'IYZWSv2 '.base64_encode($authorizationString),
            'x-iyzi-rnd' => $randomKey,
        ];
    }

    /**
     * @param  list<string>  $orderedValues
     */
    public function responseSignature(array $orderedValues, string $secretKey): string
    {
        return hash_hmac('sha256', implode(':', $orderedValues), $secretKey);
    }

    /**
     * @param  list<string>  $orderedValues
     */
    public function verifyResponseSignature(array $orderedValues, string $signature, string $secretKey): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->responseSignature($orderedValues, $secretKey), $signature);
    }

    private function randomKey(): string
    {
        return (string) (microtime(true) * 10000).random_int(100000, 999999);
    }
}
