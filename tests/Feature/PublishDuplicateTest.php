<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use IncadevUns\CoreDomain\Models\Post;
use Tests\TestCase;

class PublishDuplicateTest extends TestCase
{
    public function test_publish_returns_409_when_meta_post_id_already_exists()
    {
        // Create an existing published post with meta_post_id
        $existing = Post::create([
            'campaign_id' => 1,
            'meta_post_id' => 'dup_meta_123',
            'title' => 'Existing Published',
            'platform' => 'facebook',
            'content' => 'Existing published content',
            'content_type' => 'text',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Create a second post which we'll attempt to publish
        $draft = Post::create([
            'campaign_id' => 1,
            'title' => 'Draft to publish',
            'platform' => 'facebook',
            'content' => 'Draft content',
            'content_type' => 'text',
            'status' => 'draft',
        ]);

        // Fake socialmediaapi publish response to return the duplicate meta_post_id
        Http::fake([
            '*' => Http::response([
                'meta_post_id' => 'dup_meta_123',
                'data' => ['id' => 'dup_meta_123']
            ], 200)
        ]);

        // Call publish endpoint
        $response = $this->postJson("/api/posts/{$draft->id}/publish", []);

        // Expect 409 conflict due to duplicate meta_post_id
        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'meta_post_id already exists for another post']);
    }
}
