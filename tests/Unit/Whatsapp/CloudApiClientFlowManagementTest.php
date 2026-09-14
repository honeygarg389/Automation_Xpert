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
