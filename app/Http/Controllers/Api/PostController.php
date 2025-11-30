<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use IncadevUns\CoreDomain\Models\Post;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class PostController extends Controller
{
    /**
     * Attempt to download a remote image URL and store it on the public disk.
     * Returns stored filename on success (relative to storage/app/public), or null on failure.
     */
    protected function downloadRemoteImage(string $remoteUrl): ?string
    {
        try {
            $resp = Http::timeout(30)->get($remoteUrl);
            if (! $resp->ok()) return null;
            $mime = $resp->header('Content-Type', 'image/jpeg');
            $ext = 'jpg';
            if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) $ext = 'jpg';
            if (str_contains($mime, 'gif')) $ext = 'gif';
            $filename = 'posts/' . uniqid('', true) . '.' . $ext;
            // Try to convert to JPEG bytes to guarantee JPEG storage
            $bytes = $resp->body();
            $jpegBytes = $bytes;
            if (function_exists('imagecreatefromstring')) {
                $im = @imagecreatefromstring($bytes);
                if ($im !== false) {
                    ob_start();
                    imagejpeg($im, null, 85);
                    imagedestroy($im);
                    $jpeg = ob_get_clean();
                    if ($jpeg !== false && strlen($jpeg) > 0) {
                        $jpegBytes = $jpeg;
                    }
                }
            }
            Storage::disk('public')->put($filename, $jpegBytes);
            return $filename;
        } catch (\Throwable $e) {
            logger()->warning('Unable to download remote image: ' . $e->getMessage(), ['url' => $remoteUrl]);
            return null;
        }
    }
    // Listar posts
    public function index()
    {
        return Post::with('metrics')->get();
    }

    // Crear post
    public function store(Request $request)
    {
        // Debugging log to inspect incoming request payload and headers
        logger()->info('Incoming create post request', [
            'payload' => $request->all(),
            'content_type' => $request->header('Content-Type'),
            'raw_body' => substr((string) $request->getContent(), 0, 2000),
        ]);

        // Note: JSON parsing should be done by the framework; ensure the client sends valid UTF-8 JSON.

        // Defensive mapping: allow alternate keys from the UI if campaign id is provided under a different name
        if (! $request->filled('campaign_id')) {
            // Common variants submitted by client: postCampaignId, campaignId
            $alt = $request->input('postCampaignId') ?: $request->input('campaignId') ?: $request->input('campaign_id');
            if ($alt) {
                $request->merge(['campaign_id' => $alt]);
                logger()->info('Mapped alt campaign id to campaign_id', ['alt' => $alt]);
            }
        }

        // Keep minimal logging for incoming request; further debug logs removed to keep code clean

        try {
            $validated = $request->validate([
            'campaign_id'   => 'required|integer|exists:campaigns,id',
            'title'         => 'required|string|max:255',
            'platform'      => 'required|string|max:50',
            'content'       => 'required|string',
            'content_type'  => 'nullable|string|max:50',
            'image_path'    => 'nullable|string|max:255',
            'image_id'      => 'nullable|string',
            'link_url'      => 'nullable|string|max:255',
            'status'        => 'nullable|string|max:20',
            'scheduled_at'  => 'nullable|date',
            'published_at'  => 'nullable|date',
            'created_by'    => 'nullable|integer|exists:users,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Log full validation errors to help debugging
            logger()->warning('PostController::store validation failed', [
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);

            throw $e;
        }

        $imageUrl = $request->input('public_image_url') ?: $request->input('image_url');
        if (!empty($imageUrl) && empty($validated['image_path'])) {
            if (str_contains($imageUrl, '/api/generation/image/')) {
                $parts = explode('/api/generation/image/', $imageUrl);
                $validated['image_path'] = end($parts) ?: null;
            } elseif (str_contains($imageUrl, '/storage/generated/')) {
                $validated['image_path'] = pathinfo($imageUrl, PATHINFO_FILENAME) ?: null;
            } else {
                $downloaded = $this->downloadRemoteImage($imageUrl);
                if ($downloaded) $validated['image_path'] = $downloaded;
            }
        }

        if (empty($validated['image_path']) && $request->filled('image_id')) {
            $validated['image_path'] = $request->input('image_id');
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
        // Defensive mapping for update flow
        if (! $request->filled('campaign_id')) {
            $alt = $request->input('postCampaignId') ?: $request->input('campaignId') ?: $request->input('campaign_id');
            if ($alt) {
                $request->merge(['campaign_id' => $alt]);
                logger()->info('Mapped alt campaign id to campaign_id (update)', ['alt' => $alt]);
            }
        }
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
        // Consolidated image flow for update: prefer storing image_id, extract id from marketing URLs,
        // download only for arbitrary external URLs.
        $imageUrl = $request->input('public_image_url') ?: $request->input('image_url');
        if (!empty($imageUrl) && empty($validated['image_path'])) {
            if (str_contains($imageUrl, '/api/generation/image/')) {
                $parts = explode('/api/generation/image/', $imageUrl);
                $validated['image_path'] = end($parts) ?: null;
            } elseif (str_contains($imageUrl, '/storage/generated/')) {
                $validated['image_path'] = pathinfo($imageUrl, PATHINFO_FILENAME) ?: null;
            } else {
                $downloaded = $this->downloadRemoteImage($imageUrl);
                if ($downloaded) $validated['image_path'] = $downloaded;
            }
        }

        if (empty($validated['image_path']) && $request->filled('image_id')) {
            $validated['image_path'] = $request->input('image_id');
        }

        // If image_path is a remote URL (e.g. an absolute http(s) link) try to download / normalize it to a local public path
        if (! empty($validated['image_path']) && (str_starts_with($validated['image_path'], 'http://') || str_starts_with($validated['image_path'], 'https://'))) {
            $downloaded = $this->downloadRemoteImage($validated['image_path']);
            if ($downloaded) {
                $validated['image_path'] = $downloaded;
            }
        }

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

        $out = $posts->map(function ($p) {
            $arr = $p->toArray();
            if (! array_key_exists('meta_post_id', $arr)) {
                $arr['meta_post_id'] = $p->meta_post_id ?? null;
            }
            return $arr;
        });

        return response()->json($out);
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
                'post_id' => $post->id,
            ];

            // Use content as message/caption
            if (!empty($post->content)) {
                if ($post->platform === 'facebook') $payload['message'] = $post->content;
                if ($post->platform === 'instagram') $payload['caption'] = $post->content;
            }

            // Resolve image for publish (clean flow):
            // - If image_path is a remote URL, download it and persist locally.
            // - If image_path is a stored path (contains '/'), use that.
            // - If image_path is an image id, look under `generated/` then `posts/` for a matching file.
            // Keep resolution separate from attaching; attach after the HTTP client is prepared.
            $resolvedForAttach = null;
            $fallbackImageUrl = null;
            if (!empty($post->image_path)) {
                // Remote URL -> download and persist
                if (str_starts_with($post->image_path, 'http://') || str_starts_with($post->image_path, 'https://')) {
                    $downloaded = $this->downloadRemoteImage($post->image_path);
                    if ($downloaded) {
                        $post->image_path = $downloaded;
                        try { $post->save(); } catch (\Throwable $_) { /* ignore save errors */ }
                    }
                }

                // If it's already a stored path
                if (str_contains($post->image_path, '/')) {
                    $resolvedForAttach = $post->image_path;
                } else {
                    // Treat as image_id: search generated/ then posts/
                    $files = Storage::disk('public')->files('generated');
                    foreach ($files as $file) {
                        if (pathinfo($file, PATHINFO_FILENAME) === $post->image_path) { $resolvedForAttach = $file; break; }
                    }
                    if (empty($resolvedForAttach)) {
                        $files2 = Storage::disk('public')->files('posts');
                        foreach ($files2 as $file) {
                            if (pathinfo($file, PATHINFO_FILENAME) === $post->image_path) { $resolvedForAttach = $file; break; }
                        }
                    }
                }

                // If no local file found, prepare a fallback public URL
                if (empty($resolvedForAttach)) {
                    if (str_contains($post->image_path, '/')) {
                        $fallbackImageUrl = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/') . '/storage/' . ltrim($post->image_path, '/');
                    } else {
                        $fallbackImageUrl = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/') . '/api/generation/image/' . $post->image_path;
                    }
                }
            }

            if (!empty($post->link_url)) $payload['link'] = $post->link_url;

            // Choose endpoint based on platform
            $endpoint = null;
            if ($post->platform === 'facebook') {
                // Social API exposes a simple /api/socialmedia prefix for social endpoints.
                $endpoint = rtrim($socialBase, '/') . '/api/socialmedia/posts/facebook';
            } elseif ($post->platform === 'instagram') {
                $endpoint = rtrim($socialBase, '/') . '/api/socialmedia/posts/instagram';
            } else {
                return response()->json(['success' => false, 'message' => 'Unsupported platform'], 400);
            }

            // Call socialmedia api
            // Prepare a client that asks for JSON responses to avoid HTML redirect pages
            $client = Http::timeout(30)->acceptJson();

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

            // Attach resolved local file (if any); otherwise, ensure a fallback public URL is provided.
            $attached = false;
            if (!empty($resolvedForAttach) && Storage::disk('public')->exists($resolvedForAttach)) {
                $localPath = storage_path('app/public/' . $resolvedForAttach);
                try {
                    $fileContents = file_get_contents($localPath);
                    $client = $client->attach('image', $fileContents, basename($localPath))->asMultipart();
                    unset($payload['image_url']);
                    $attached = true;
                } catch (\Throwable $e) {
                    Log::warning('Unable to attach resolved image file to social publish - keeping image_url', ['path' => $localPath, 'error' => $e->getMessage()]);
                }
            }

            if (! $attached && ! empty($fallbackImageUrl)) {
                $payload['image_url'] = $fallbackImageUrl;
            }

            Log::info('Forwarding publish to social API', ['endpoint' => $endpoint, 'payload' => $payload]);
            $resp = $client->post($endpoint, $payload);

            if (! $resp->ok()) {
                $body = $resp->body();
                $status = $resp->status();
                Log::warning('Social publish failed', ['status' => $status, 'body' => $body]);

                // Try return the original social API response status and JSON body
                try {
                    $json = $resp->json();
                    return response()->json($json, $status);
                } catch (\Throwable $e) {
                    return response()->json(['success' => false, 'message' => $body], $status);
                }
            }

            $json = $resp->json();
            // If the social API returned a specific error telling us the Meta token is missing, expose a helpful message
            if (isset($json['error']) && isset($json['details']) && is_array($json['details']) && isset($json['details']['error']) && $json['details']['error'] === 'page_access_token_missing') {
                Log::error('Social API returned page_access_token_missing', ['endpoint' => $endpoint, 'payload' => $payload, 'response' => $json]);
                return response()->json(['success' => false, 'message' => 'Social Media API is misconfigured: META_PAGE_ACCESS_TOKEN is missing or invalid'], 502);
            }
            // Log the social API response for debugging
            Log::info('Social publish response', ['status' => $resp->status(), 'body' => $json]);
            // social media returns either meta_post_id or nested data.id
            $metaId = $json['meta_post_id'] ?? data_get($json, 'data.id') ?? data_get($json, 'id');

            if (empty($metaId)) {
                return response()->json(['success' => false, 'message' => 'No meta post id returned from social API', 'response' => $json], 502);
            }


            // Update local Post record
            $post->meta_post_id = $metaId;
            $post->status = 'published';
            $post->published_at = now();
            try {
                $post->save();
            } catch (QueryException $e) {
                // Handle duplicate meta_post_id (race condition) gracefully
                if ($e->getCode() == 23000 && str_contains($e->getMessage(), 'posts_meta_post_id_unique')) {
                    Log::warning('Duplicate meta_post_id detected during save', ['meta_post_id' => $metaId, 'post_id' => $post->id, 'error' => $e->getMessage()]);
                    $existing = Post::where('meta_post_id', $metaId)->first();
                    return response()->json(['success' => false, 'message' => 'meta_post_id already exists for another post', 'meta_post_id' => $metaId, 'existing_post_id' => $existing ? $existing->id : null], 409);
                }
                throw $e;
            }

            // No cleanup or deduplication: the socialmedia API is responsible for creating/updating the canonical post.

            return response()->json(['success' => true, 'post' => $post, 'remote' => $json]);

        } catch (\Throwable $e) {
            Log::error('Publish error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error publishing post', 'error' => $e->getMessage()], 500);
        }
    }
}