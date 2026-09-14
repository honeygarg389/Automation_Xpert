<?php

namespace Tests\Unit\Whatsapp;

use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloudApiClientFlowManagementTest extends TestCase
{
    #[Test]
    public function upload_encryption_key_uses_the_documented_multipart_business_public_key_field(): void
    {
        Http::fake(['https://graph.facebook.com/v20.0/phone-123/whatsapp_business_encryption' => Http::response(['success' => true])]);

        $response = (new CloudApiClient('phone-123', 'token'))->uploadEncryptionKey('phone-123', "-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----");

        $this->assertTrue($response->successful());
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v20.0/phone-123/whatsapp_business_encryption'
            && str_contains($request->body(), 'business_public_key'));
    }

    #[Test]
    public function get_flow_requests_the_current_documented_flow_fields_using_the_client_api_version(): void
    {
        Http::fake(['https://graph.facebook.com/v20.0/flow-123*' => Http::response([
            'id' => 'flow-123', 'status' => 'DRAFT', 'validation_errors' => [], 'json_version' => '6.3',
        ])]);

        $response = (new CloudApiClient('phone-123', 'token'))->getFlow('flow-123');

        $this->assertTrue($response->successful());
        $this->assertSame('DRAFT', $response->json('status'));
        Http::assertSent(function (HttpRequest $request): bool {
            return str_starts_with($request->url(), 'https://graph.facebook.com/v20.0/flow-123?')
                && str_contains($request->url(), 'validation_errors')
                && $request->method() === 'GET';
        });
    }
}
