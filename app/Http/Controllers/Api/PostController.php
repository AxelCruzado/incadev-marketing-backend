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

        // If frontend provided a temporary image URL (from the generator microservice) or
        // if image_path contains a remote URL, download it and save it locally so the DB stores our own path.
        if ($request->filled('image_url') && empty($validated['image_path'])) {
            $imageUrl = $request->input('image_url');
            try {
                $resp = Http::timeout(15)->get($imageUrl);
                if ($resp->ok()) {
                    $mime = $resp->header('Content-Type', 'image/jpeg');
                    // try to determine extension
                    $ext = 'jpg';
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

        // If caller set image_path but it's a remote URL (e.g., http://...), try to download it locally
        if (! empty($validated['image_path']) && (str_starts_with($validated['image_path'], 'http://') || str_starts_with($validated['image_path'], 'https://'))) {
            $downloaded = $this->downloadRemoteImage($validated['image_path']);
            if ($downloaded) {
                $validated['image_path'] = $downloaded;
            }
        }

        // If no image_path but generator provided an image_id, try to fetch from generation microservice
        if (empty($validated['image_path']) && $request->filled('image_id')) {
            $imageId = $request->input('image_id');
            $genBase = config('services.generative_api.url', 'http://127.0.0.1:8004');
            try {
                $downloaded = $this->downloadRemoteImage(rtrim($genBase, '/') . '/api/generation/image/' . urlencode($imageId));
                if ($downloaded) {
                    $validated['image_path'] = $downloaded;
                }
            } catch (\Throwable $e) {
                logger()->warning('Unable to download generated image from generative API: ' . $e->getMessage());
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
        // If no image_path but generator provided an image_id, try to fetch from generation microservice
        if (empty($validated['image_path']) && $request->filled('image_id')) {
            $imageId = $request->input('image_id');
            $genBase = config('services.generative_api.url', 'http://127.0.0.1:8004');
            try {
                $downloaded = $this->downloadRemoteImage(rtrim($genBase, '/') . '/api/generation/image/' . urlencode($imageId));
                if ($downloaded) {
                    $validated['image_path'] = $downloaded;
                }
            } catch (\Throwable $e) {
                logger()->warning('Unable to download generated image from generative API: ' . $e->getMessage());
            }
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
                'post_id' => $post->id,
            ];

            // Use content as message/caption
            if (!empty($post->content)) {
                if ($post->platform === 'facebook') $payload['message'] = $post->content;
                if ($post->platform === 'instagram') $payload['caption'] = $post->content;
            }

            // Provide image URL if available
            if (!empty($post->image_path)) {
                // If image_path appears to be a remote HTTP URL, download it and persist as a local public path
                if (str_starts_with($post->image_path, 'http://') || str_starts_with($post->image_path, 'https://')) {
                    Log::info('Post image_path is remote URL, attempting to download into public storage', ['image_path' => $post->image_path]);
                    $downloaded = $this->downloadRemoteImage($post->image_path);
                    if ($downloaded) {
                        $post->image_path = $downloaded;
                        try { $post->save(); } catch (\Throwable $e) { Log::warning('Failed to save downloaded image path to post', ['post_id' => $post->id, 'error' => $e->getMessage()]); }
                    }
                }
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

            // If we have a local image_path (stored in public disk), attach it as multipart 'image' so
            // the socialmedia API will receive the file and publish using a local upload rather
            // than passing a public URL (Meta Graph requires publicly accessible URLs otherwise).
            if (! empty($post->image_path) && Storage::disk('public')->exists($post->image_path)) {
                $localPath = storage_path('app/public/' . $post->image_path);
                try {
                    $fileContents = file_get_contents($localPath);
                    $client = $client->attach('image', $fileContents, basename($localPath))->asMultipart();
                    // Remove image_url from payload to avoid confusion on the remote side
                    unset($payload['image_url']);
                } catch (\Throwable $e) {
                    Log::warning('Unable to attach image file to social publish - keeping image_url', ['path' => $localPath, 'error' => $e->getMessage()]);
                }
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