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
}
