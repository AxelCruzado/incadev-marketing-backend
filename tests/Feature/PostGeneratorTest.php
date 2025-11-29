<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostGeneratorTest extends TestCase
{
    public function test_generate_draft_returns_default_suggestion_and_image_preview()
    {
        // Fake generative API responses for text and image
        Http::fake([
            '*/api/v1/marketing/generation/instagram' => Http::response([
                'payload' => ['generated_text' => 'Texto generado de prueba para Instagram.']
            ], 200),
            '*/api/v1/marketing/generation/image' => Http::response([
                'saved_images' => [['id' => 'abc123']],
            ], 200),
            '*/api/v1/marketing/generation/image/abc123' => Http::response(['url' => 'http://127.0.0.1:8004/api/v1/marketing/generation/image/abc123'], 200),
        ]);

        $response = $this->postJson('/api/posts/generate-draft', [
            'prompt' => 'Curso de programación básica',
            'platform' => 'instagram',
            'content_type' => 'image'
        ]);

        $response->assertStatus(200);

        $body = $response->json('data');
        $this->assertArrayHasKey('default_suggestion', $body);
        $this->assertArrayHasKey('variants', $body);
        $this->assertArrayHasKey('image_preview', $body);
        $this->assertTrue($body['image_generated']);
    }

    public function test_generate_draft_normalizes_upstream_url_to_marketing_base()
    {
        // Simulate a generator returning an absolute URL pointing to the generativeapi host
        Http::fake([
            '*/api/v1/marketing/generation/instagram' => Http::response([
                'payload' => ['generated_text' => 'Texto generado.']
            ], 200),
            '*/api/v1/marketing/generation/image' => Http::response([
                'image_url' => 'http://127.0.0.1:8004/api/v1/marketing/generation/image/xyz789'
            ], 200),
        ]);

        $response = $this->postJson('/api/posts/generate-draft', [
            'prompt' => 'Prueba',
            'platform' => 'instagram',
            'content_type' => 'image'
        ]);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data['image_preview']);
        // Ensure we rewrote the upstream host and returned a marketing-backend accessible path
        $this->assertStringNotContainsString('8004', $data['image_preview']);
        $this->assertStringEndsWith('/api/v1/marketing/generation/image/xyz789', $data['image_preview']);
    }

    public function test_proxy_generated_image_endpoint_returns_image()
    {
        // Simulate generative service returning an image file for the id
        Http::fake([
            '*/api/v1/marketing/generation/image/abc123' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline']),
        ]);

        $response = $this->get('/api/v1/marketing/generation/image/abc123');
        $response->assertStatus(200);
        $this->assertStringContainsString('image', $response->headers->get('content-type'));
    }
}
