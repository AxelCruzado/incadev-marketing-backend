<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use IncadevUns\CoreDomain\Models\Post;
use Illuminate\Support\Facades\Log;

class PostController extends Controller
{
    // Listar posts
    public function index()
    {
        return Post::with('metrics')->get();
    }

    // Crear post
    public function store(Request $request)
    {
        $validated = $request->validate([
            'campaign_id'   => 'required|integer|exists:campaigns,id',
            'title'         => 'required|string|max:255',
            'platform'      => 'required|string|max:50',
            'content'       => 'required|string',
            'content_type'  => 'nullable|string|max:50',
            'image_path'    => 'nullable|string|max:255',
            'link_url'      => 'nullable|string|max:255',
            'status'        => 'nullable|string|max:20',
            'scheduled_at'  => 'nullable|date',
            'published_at'  => 'nullable|date',
            'created_by'    => 'nullable|integer|exists:users,id',
        ]);

        // If frontend provided a temporary image URL (from the generator microservice),
        // download it and save it locally so the DB stores our own path.
        if (empty($validated['image_path']) && $request->filled('image_url')) {
            $imageUrl = $request->input('image_url');
            try {
                $resp = Http::timeout(15)->get($imageUrl);
                if ($resp->ok()) {
                    $mime = $resp->header('Content-Type', 'image/png');
                    // try to determine extension
                    $ext = 'png';
                    if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) $ext = 'jpg';
                    if (str_contains($mime, 'gif')) $ext = 'gif';

                    $filename = 'posts/' . uniqid('', true) . '.' . $ext;
                    Storage::disk('public')->put($filename, $resp->body());
                    $validated['image_path'] = $filename;
                }
            } catch (\Throwable $e) {
                // ignore the error and continue without image
                logger()->warning('Unable to download remote image: ' . $e->getMessage());
            }
        }

        $post = Post::create($validated);

        return response()->json($post, 201);
    }

    // Mostrar un post
    public function show($id)
    {
        return Post::with('metrics')->findOrFail($id);
    }

    // Actualizar post
    public function update(Request $request, $id)
    {
        $post = Post::findOrFail($id);

        $validated = $request->validate([
            'title'         => 'sometimes|string|max:255',
            'platform'      => 'sometimes|string|max:50',
            'content'       => 'sometimes|string',
            'content_type'  => 'nullable|string|max:50',
            'image_path'    => 'nullable|string|max:255',
            'link_url'      => 'nullable|string|max:255',
            'status'        => 'nullable|string|max:20',
            'scheduled_at'  => 'nullable|date',
            'published_at'  => 'nullable|date',
        ]);

        $post->update($validated);

        return response()->json($post);
    }

    // Eliminar post
    public function destroy($id)
    {
        $post = Post::findOrFail($id);
        $post->delete();

        return response()->json(['message' => 'Post deleted']);
    }

    // Listar posts por campaña con sus métricas
    public function byCampaign($id)
    {
        $posts = Post::where('campaign_id', $id)
            ->with('metrics')
            ->get();
            
        return response()->json($posts);
    }

    // Obtener todas las métricas de un post
    public function metrics($id)
    {
        $post = Post::with('metrics')->findOrFail($id);

        return response()->json([
            'success' => true,
            'post_id' => $post->id,
            'post_title' => $post->title,
            'platform' => $post->platform,
            'metrics' => $post->metrics,
        ]);
    }

    /**
     * Publish an existing post to the configured socialmedia API.
     * This will call socialmediaapi and on success will update the local post's status/meta_post_id/published_at.
     */
    public function publish(Request $request, $id)
    {
        $post = Post::findOrFail($id);

        if ($post->status === 'published') {
            return response()->json(['success' => false, 'message' => 'Post already published'], 400);
        }

        // Decide which social endpoint to call
        $socialBase = config('services.social_api.url', 'http://127.0.0.1:8005');

        try {
            $payload = [
                'campaign_id' => $post->campaign_id,
            ];

            // Use content as message/caption
            if (!empty($post->content)) {
                if ($post->platform === 'facebook') $payload['message'] = $post->content;
                if ($post->platform === 'instagram') $payload['caption'] = $post->content;
            }

            // Provide image URL if available
            if (!empty($post->image_path)) {
                // Try to build a public URL for the image (assumes public disk)
                try {
                    $imgUrl = Storage::disk('public')->url($post->image_path);
                } catch (\Throwable $e) {
                    $imgUrl = null;
                }
                if ($imgUrl) {
                    $payload['image_url'] = $imgUrl;
                }
            }

            if (!empty($post->link_url)) $payload['link'] = $post->link_url;

            // Choose endpoint based on platform
            $endpoint = null;
            if ($post->platform === 'facebook') {
                $endpoint = rtrim($socialBase, '/') . '/api/v1/marketing/socialmedia/posts/facebook';
            } elseif ($post->platform === 'instagram') {
                $endpoint = rtrim($socialBase, '/') . '/api/v1/marketing/socialmedia/posts/instagram';
            } else {
                return response()->json(['success' => false, 'message' => 'Unsupported platform'], 400);
            }

            // Call socialmedia api
            $client = Http::timeout(30);

            // Prefer an explicit service token if configured (internal calls), otherwise forward
            // the Authorization header that the UI sent (if present) so the social API sees the same user token.
            $socialToken = config('services.social_api.token');
            if (!empty($socialToken)) {
                // Using an explicit internal service token for requests to social API
                Log::info('Using configured social_api service token for publish call');
                $client = $client->withToken($socialToken);
            } else {
                // Forward incoming Authorization header if available
                $incomingToken = $request->bearerToken();
                if (!empty($incomingToken)) {
                    $masked = substr($incomingToken, 0, 8) . '…';
                    Log::info('Forwarding incoming bearer token to social API', ['token_preview' => $masked]);
                    $client = $client->withToken($incomingToken);
                } else {
                    Log::info('No social token configured and no incoming Authorization header present');
                }
            }

            $resp = $client->post($endpoint, $payload);

            if (! $resp->ok()) {
                $body = $resp->body();
                Log::warning('Social publish failed', ['status' => $resp->status(), 'body' => $body]);
                return response()->json(['success' => false, 'message' => 'Failed to publish to social media', 'details' => $body], 502);
            }

            $json = $resp->json();
            // Log the social API response for debugging
            Log::info('Social publish response', ['status' => $resp->status(), 'body' => $json]);
            // social media returns either meta_post_id or nested data.id
            $metaId = $json['meta_post_id'] ?? data_get($json, 'data.id') ?? data_get($json, 'id');

            if (empty($metaId)) {
                return response()->json(['success' => false, 'message' => 'No meta post id returned from social API', 'response' => $json], 502);
            }

            // Defensive check: make sure this meta_post_id isn't already used by another Post
            if (!empty($metaId)) {
                $existing = Post::where('meta_post_id', $metaId)->first();
                if ($existing && $existing->id !== $post->id) {
                    Log::warning('Duplicate meta_post_id detected when publishing', ['meta_post_id' => $metaId, 'post_id' => $post->id, 'existing_post_id' => $existing->id]);
                    return response()->json(['success' => false, 'message' => 'meta_post_id already exists for another post', 'meta_post_id' => $metaId, 'existing_post_id' => $existing->id], 409);
                }
            }

            // Update local Post record
            $post->meta_post_id = $metaId;
            $post->status = 'published';
            $post->published_at = now();
            $post->save();

            // If this post was just published, remove any related draft posts to avoid duplicates.
            try {
                $query = Post::where('campaign_id', $post->campaign_id)
                    ->where('platform', $post->platform)
                    ->where('status', 'draft')
                    ->where('id', '!=', $post->id)
                    ->where(function ($q) use ($post) {
                        $q->where('title', $post->title);
                        if (!empty($post->content)) $q->orWhere('content', $post->content);
                        if (!empty($post->image_path)) $q->orWhere('image_path', $post->image_path);
                        if (!empty($post->link_url)) $q->orWhere('link_url', $post->link_url);
                    });

                $drafts = $query->get();
                if ($drafts->isNotEmpty()) {
                    foreach ($drafts as $d) {
                        Log::info('Removing draft post after publish', ['published_post_id' => $post->id, 'removed_draft_id' => $d->id]);
                        $d->delete();
                    }
                }
            } catch (\Throwable $e) {
                // Don't block success if cleanup fails — just log
                Log::warning('Failed to remove draft posts after publish', ['error' => $e->getMessage(), 'post_id' => $post->id]);
            }

            return response()->json(['success' => true, 'post' => $post, 'remote' => $json]);

        } catch (\Throwable $e) {
            Log::error('Publish error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error publishing post', 'error' => $e->getMessage()], 500);
        }
    }
}