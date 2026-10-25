<?php

namespace Tests\Unit\Comail;

use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MailcowClientTest extends TestCase
{
    private const BASE = 'https://mail.example.test/api/v1';

    private const KEY = 'unit-mailcow-key-never-logged';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_sends_the_api_key_and_reads_the_version(): void
    {
        Http::fake([self::BASE.'/get/status/version' => Http::response(['version' => '2026-09'])]);

        $this->assertSame('2026-09', $this->client()->version());

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-Key', self::KEY)
            && $request->url() === self::BASE.'/get/status/version');
    }

    public function test_write_with_several_success_elements_passes(): void
    {
        Http::fake([self::BASE.'/add/domain' => Http::response([
            ['type' => 'success', 'log' => [], 'msg' => ['dkim_added', 'acme.test']],
            ['type' => 'success', 'log' => [], 'msg' => ['domain_added', 'acme.test']],
        ])]);

        $result = $this->client()->addDomain('acme.test', ['mailboxes' => 1]);

        $this->assertCount(2, $result);
        Http::assertSent(fn (Request $request): bool => $request['domain'] === 'acme.test' && $request['mailboxes'] === 1);
    }

    public function test_new_mailboxes_never_get_sogo_access(): void
    {
        Http::fake([self::BASE.'/add/mailbox' => Http::response([['type' => 'success', 'msg' => ['mailbox_added', 'a@acme.test']]])]);

        $this->client()->addMailbox(['local_part' => 'a', 'domain' => 'acme.test', 'password' => 'Secret-123', 'sogo_access' => 1]);

        Http::assertSent(fn (Request $request): bool => $request['sogo_access'] === 0 && $request['local_part'] === 'a');
    }

    public function test_danger_element_in_a_200_answer_throws_with_the_translation_key(): void
    {
        Http::fake([self::BASE.'/add/mailbox' => Http::response([
            ['type' => 'success', 'msg' => 'ok'],
            ['type' => 'danger', 'log' => ['mailbox', 'add'], 'msg' => ['mailbox_quota_exceeded', '1024']],
        ])]);

        try {
            $this->client()->addMailbox(['local_part' => 'a', 'domain' => 'acme.test', 'password' => 'Secret-123']);
            $this->fail('Expected MailcowException');
        } catch (MailcowException $exception) {
            $this->assertSame('provider_error', $exception->error);
            $this->assertSame('mailbox_quota_exceeded', $exception->providerKey);
            $this->assertSame('Posta kutusu kotası alan adının toplam kotasını aşıyor.', $exception->turkishMessage());
        }
    }

    public function test_error_type_and_empty_results_throw(): void
    {
        Http::fake([
            self::BASE.'/delete/alias' => Http::response([['type' => 'error', 'msg' => 'access_denied']]),
            self::BASE.'/delete/mailbox' => Http::response([]),
        ]);

        $this->expectException(MailcowException::class);
        try {
            $this->client()->deleteAlias(4);
        } catch (MailcowException $exception) {
            $this->assertSame('access_denied', $exception->providerKey);
        }

        $this->client()->deleteMailbox('a@acme.test');
    }

    public function test_get_normalises_empty_objects_lists_and_lone_objects(): void
    {
        Http::fake([
            self::BASE.'/get/domain/missing.test' => Http::response([]),
            self::BASE.'/get/domain/acme.test' => Http::response(['domain_name' => 'acme.test']),
            self::BASE.'/get/mailbox/all/acme.test' => Http::response(['username' => 'a@acme.test']),
            self::BASE.'/get/mailbox/all/empty.test' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
            self::BASE.'/get/dkim/acme.test' => Http::response(['dkim_txt' => '', 'dkim_selector' => 'dkim']),
        ]);

        $client = $this->client();
        $this->assertNull($client->domain('missing.test'));
        $this->assertSame('acme.test', $client->domain('acme.test')['domain_name']);
        $this->assertSame([['username' => 'a@acme.test']], $client->mailboxes('acme.test'));
        $this->assertSame([], $client->mailboxes('empty.test'));
        $this->assertNull($client->dkim('acme.test'));
    }

    public function test_unauthorized_and_http_errors_throw_without_leaking_the_key(): void
    {
        Log::spy();
        Http::fake([
            self::BASE.'/get/alias/all' => Http::response(['type' => 'error', 'msg' => 'authentication failed'], 401),
            self::BASE.'/get/filters/all' => Http::response('oops', 500),
        ]);

        foreach (['aliases', 'filters'] as $method) {
            try {
                $this->client()->{$method}();
                $this->fail('Expected MailcowException');
            } catch (MailcowException $exception) {
                $this->assertSame('provider_error', $exception->error);
                $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
            }
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains((string) json_encode($context), self::KEY));
    }

    public function test_addresses_in_paths_are_encoded_and_redacted_in_logs(): void
    {
        Log::spy();
        Http::fake([self::BASE.'/get/mailbox/*' => Http::response('nope', 500)]);

        try {
            $this->client()->mailbox('info@acme.test');
        } catch (MailcowException) {
        }

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/get/mailbox/info%40acme.test'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains((string) json_encode($context), 'info@acme.test'));
    }

    private function client(): MailcowClient
    {
        return new MailcowClient(self::BASE, self::KEY, 5);
    }
}
