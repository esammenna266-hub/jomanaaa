<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The web app and the API usually live on different addresses (a Vercel domain and the API host): only the listed
 * websites may call the API from a browser.
 */
class CorsTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);
    }

    public function test_a_listed_website_may_call_the_api(): void
    {
        config(['cors.allowed_origins' => ['https://gomana-demo.vercel.app']]);

        $response = $this->preflight('https://gomana-demo.vercel.app');

        $this->assertSame('https://gomana-demo.vercel.app', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_any_other_website_gets_no_permission(): void
    {
        config(['cors.allowed_origins' => ['https://gomana-demo.vercel.app']]);

        // With one allowed site the answer always names that site, and a browser only accepts it from that very site.
        $this->assertNotSame('https://evil.example.com', $this->preflight('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $this->preflight('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_nothing_is_allowed_until_origins_are_configured(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->assertNull($this->preflight('https://gomana-demo.vercel.app')->headers->get('Access-Control-Allow-Origin'));
    }
}
