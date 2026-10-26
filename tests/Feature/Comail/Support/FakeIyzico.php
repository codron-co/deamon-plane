<?php

namespace Tests\Feature\Comail\Support;

use App\Services\Billing\IyzicoCheckout;
use App\Services\Billing\IyzicoSignatureBuilder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * iyzico Checkout Form for Http::fake: checks the IYZWSv2 header against the
 * exact body sent, answers initialize with a token and signs retrieve answers
 * like the real API (status, paymentStatus, …, token joined by ':').
 */
class FakeIyzico
{
    public const API_KEY = 'sandbox-iyzico-api-key';

    public const SECRET = 'sandbox-iyzico-secret-never-show';

    /** @var array<string, array<string, mixed>> token => initialize body */
    public array $checkouts = [];

    /** @var array<string, string> token => SUCCESS | FAILURE */
    public array $outcomes = [];

    /** @var array<string, string> token => paidPrice override */
    public array $paidPrices = [];

    /** Sign retrieve answers with this secret instead (a forged answer). */
    public ?string $signWith = null;

    public bool $failInitialize = false;

    /** @var list<array{path: string, body: array<string, mixed>, signature_ok: bool, host: string}> */
    public array $calls = [];

    private int $next = 1;

    /**
     * @return array<string, callable>
     */
    public function fake(): array
    {
        $handler = fn (Request $request): PromiseInterface => $this->handle($request);

        return [
            IyzicoCheckout::SANDBOX_BASE_URL.'/*' => $handler,
            IyzicoCheckout::LIVE_BASE_URL.'/*' => $handler,
        ];
    }

    public function handle(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $raw = $request->body();
        $body = json_decode($raw, true) ?: [];
        $this->calls[] = [
            'path' => $path,
            'body' => $body,
            'signature_ok' => $this->authorizationValid($request, $path, $raw),
            'host' => (string) parse_url($request->url(), PHP_URL_HOST),
        ];

        if (! $this->authorizationValid($request, $path, $raw)) {
            return Http::response(['status' => 'failure', 'errorCode' => '1001', 'errorMessage' => 'Geçersiz imza'], 200);
        }

        if ($path === IyzicoCheckout::INITIALIZE_PATH) {
            if ($this->failInitialize) {
                return Http::response(['status' => 'failure', 'errorCode' => '5', 'errorMessage' => 'Geçersiz istek'], 200);
            }
            $token = 'cf-token-'.$this->next++;
            $this->checkouts[$token] = $body;

            return Http::response([
                'status' => 'success',
                'token' => $token,
                'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token='.$token.'&lang=tr',
                'checkoutFormContent' => '<script>/* iyzico */</script>',
                'tokenExpireTime' => 1800,
            ], 200);
        }

        if ($path === IyzicoCheckout::RETRIEVE_PATH) {
            $token = (string) ($body['token'] ?? '');
            $checkout = $this->checkouts[$token] ?? null;
            if ($checkout === null) {
                return Http::response(['status' => 'failure', 'errorCode' => '5', 'errorMessage' => 'Token bulunamadı'], 200);
            }

            $outcome = $this->outcomes[$token] ?? 'SUCCESS';
            $answer = [
                'status' => 'success',
                'paymentStatus' => $outcome,
                'paymentId' => $outcome === 'SUCCESS' ? '2345678' : '',
                'currency' => $checkout['currency'],
                'basketId' => $checkout['basketId'],
                'conversationId' => $checkout['conversationId'],
                'paidPrice' => $this->paidPrices[$token] ?? $checkout['paidPrice'],
                'price' => $checkout['price'],
                'token' => $token,
                'installment' => 1,
                'fraudStatus' => 1,
                // Card fields iyzico really returns; Plane must not keep them.
                'binNumber' => '552879',
                'lastFourDigits' => '0008',
                'cardHolderName' => 'Test Kart',
                'cardAssociation' => 'MASTER_CARD',
                'errorMessage' => $outcome === 'SUCCESS' ? null : 'Kart reddedildi',
            ];
            $answer['signature'] = (new IyzicoSignatureBuilder)->responseSignature([
                (string) $answer['paymentStatus'],
                (string) $answer['paymentId'],
                (string) $answer['currency'],
                (string) $answer['basketId'],
                (string) $answer['conversationId'],
                (string) $answer['paidPrice'],
                (string) $answer['price'],
                (string) $answer['token'],
            ], $this->signWith ?? self::SECRET);

            return Http::response($answer, 200);
        }

        return Http::response(['status' => 'failure', 'errorMessage' => 'unknown path'], 404);
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (array $call): string => $call['path'], $this->calls);
    }

    private function authorizationValid(Request $request, string $path, string $raw): bool
    {
        $header = (string) ($request->header('Authorization')[0] ?? '');
        $random = (string) ($request->header('x-iyzi-rnd')[0] ?? '');
        if (! str_starts_with($header, 'IYZWSv2 ') || $random === '') {
            return false;
        }

        $decoded = (string) base64_decode(substr($header, 8), true);
        $expected = 'apiKey:'.self::API_KEY.'&randomKey:'.$random.'&signature:'.hash_hmac('sha256', $random.$path.$raw, self::SECRET);

        return hash_equals($expected, $decoded);
    }
}
