<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
class PostGeneratorController extends Controller
{
    /**
     * Generate a draft using the generation microservices (text + image) in parallel
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|min:5',
            'platform' => 'required|string|in:facebook,instagram',
            'content_type' => 'required|string|in:image,text,video',
            // optionally accept an explicit image url/path to use as preview if provided
            'image_url' => 'sometimes|nullable|url',
            'link_url' => 'sometimes|nullable|url',
        ]);

        $prompt = $request->input('prompt');
        $platform = $request->input('platform');
        $contentType = $request->input('content_type');
        $linkUrl = $request->input('link_url');

        // Instagram does not accept text-only posts; enforce it here
        if ($platform === 'instagram' && $contentType === 'text') {
            return response()->json(['success' => false, 'message' => 'Instagram no admite publicaciones solo de texto. Seleccione un tipo con imagen o video.'], 422);
        }

        // Base URL for the generative microservice (adjust if different env)
        $base = config('services.generative_api.url', 'http://127.0.0.1:8004');

        try {
            $textoEndpoint = rtrim($base, '/') . '/api/generation/' . $platform;

            $responses = [];
            try {
                $responses['text'] = Http::timeout(60)->post($textoEndpoint, [
                    'prompt' => $prompt,
                    'content_type' => $contentType,
                    'link_url' => $linkUrl,
                ]);
            } catch (\Throwable $e) {
                $responses['text'] = $e;
            }

            if ($contentType === 'image') {
                $imagenEndpoint = rtrim($base, '/') . '/api/generation/image';
                try {
                    // Allow long-running image generation (up to 5 minutes)
                    $responses['image'] = Http::timeout(300)->post($imagenEndpoint, [
                        'prompt' => $prompt,
                        'sampleCount' => 1
                    ]);
                } catch (\Throwable $e) {
                    $responses['image'] = $e;
                }
            }

            // Process text response (the microservice returns payload with candidates)
            $texto = 'No se pudo generar el texto.';
            if (isset($responses['text'])) {
                $r = $responses['text'];
                if ($r instanceof \Throwable) {
                    Log::warning('Text generation failed: ' . $r->getMessage());
                } elseif ($r->ok()) {
                    $payload = $r->json();
                    // Prefer the cleaned generated_text if provided by the generative microservice
                    $texto = data_get($payload, 'payload.generated_text') ?? data_get($payload, 'payload.candidates.0.content.parts.0.text') ?? data_get($payload, 'text') ?? data_get($payload, 'generated_text') ?? $texto;
                }
            }

            // if the caller provided an image_url, prefer it as the preview
            $providedImageUrl = $request->input('image_url');
            $tempImageUrl = $providedImageUrl ?: null;
            // When we save a local copy, capture its storage path so we can produce a direct public URL
            $localSavedPath = null;
            if (isset($responses['image'])) {
                $r = $responses['image'];
                if ($r instanceof \Throwable) {
                    Log::warning('Image generation failed: ' . $r->getMessage());
                } elseif ($r->ok()) {
                    $payload = $r->json();
                    $saved = data_get($payload, 'saved_images.0');
                            if ($saved && isset($saved['id'])) {
                                // Ensure we have a local copy saved for this upstream id so other
                                // microservices can fetch via the marketing backend host.
                                $marketingBase = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/');
                                try {
                                    $localSavedPath = $this->ensureLocalFromUpstreamId($saved['id'], $base);
                                } catch (\Throwable $e) {
                                    Log::warning('Failed saving upstream generated image locally: ' . $e->getMessage());
                                }
                                // Marketing-backend-hosted preview URL
                                $tempImageUrl = $marketingBase . '/api/generation/image/' . $saved['id'];

                            } else {
                                $rawUrl = data_get($payload, 'image_url') ?? data_get($payload, 'url') ?? null;
                                // Normalize absolute URLs coming from the generative service so the UI
                                // can rely on the marketing api host (apiUrl) in production.
                                if (!empty($rawUrl)) {
                                    $marketingBase = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/');
                                    $genBase = rtrim(config('services.generative_api.url', 'http://127.0.0.1:8004'), '/');
                                    try {
                                        $parts = parse_url($rawUrl);
                                        $path = $parts['path'] ?? null;
                                        // If path looks like the generative image get endpoint, extract the id
                                        if ($path && str_contains($path, '/api/generation/image')) {
                                            $segments = array_values(array_filter(explode('/', $path)));
                                            $id = end($segments);
                                            if (!empty($id)) {
                                                // Try to fetch and save locally
                                                try {
                                                    $localSavedPath = $this->ensureLocalFromUpstreamId($id, $genBase);
                                                    $tempImageUrl = $marketingBase . '/api/generation/image/' . $id;
                                                } catch (\Throwable $e) {
                                                    Log::warning('Failed ensuring upstream image locally: ' . $e->getMessage());
                                                    $tempImageUrl = $marketingBase . '/api/generation/image/' . $id;
                                                }
                                            }
                                        }
                                        // If the URL host matches the generative service base, but doesn't include path, rewrite host
                                        if (empty($tempImageUrl) && !empty($parts['host'])) {
                                            $genHost = parse_url($genBase, PHP_URL_HOST);
                                            if ($genHost && str_contains($parts['host'], $genHost)) {
                                                // Replace host with marketing base host and keep path
                                                $path = $parts['path'] ?? '';
                                                // Attempt to download the url and save locally
                                                $local = $this->ensureLocalDownloadedFromUrl($rawUrl);
                                                if ($local) {
                                                    $localSavedPath = $local;
                                                    $localId = pathinfo($local, PATHINFO_FILENAME);
                                                    $tempImageUrl = $marketingBase . '/api/generation/image/' . $localId;
                                                } else {
                                                    $tempImageUrl = $marketingBase . $path;
                                                }
                                            }
                                        }
                                    } catch (\Throwable $e) {
                                        // Fallback to raw URL if parsing fails
                                        $tempImageUrl = $rawUrl;
                                    }

                                    // As a final fallback, if we still didn't build a marketing URL, use the raw one
                                    if (empty($tempImageUrl)) {
                                        // Try to download raw url and serve locally if possible
                                        $local = $this->ensureLocalDownloadedFromUrl($rawUrl);
                                        if ($local) {
                                            $localSavedPath = $local;
                                            $localId = pathinfo($local, PATHINFO_FILENAME);
                                            $tempImageUrl = $marketingBase . '/api/generation/image/' . $localId;
                                        } else {
                                            $tempImageUrl = $rawUrl;
                                        }
                                    }
                                } else {
                                    $tempImageUrl = null;
                                }
                            }
                }
            }

            // Compute a direct public URL for Meta if we have a saved local path
            $publicImageUrl = null;
            try {
                if (!empty($localSavedPath) && Storage::disk('public')->exists($localSavedPath)) {
                    $appUrl = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/');
                    $publicImageUrl = $appUrl . '/storage/' . ltrim($localSavedPath, '/');
                } else {
                    // If tempImageUrl references the marketing backend proxy with the id, try to resolve local file
                    if (!empty($tempImageUrl) && str_contains($tempImageUrl, '/api/generation/image/')) {
                        $parts = explode('/api/generation/image/', $tempImageUrl);
                        $maybeId = end($parts);
                        $found = $this->findLocalGeneratedFile($maybeId);
                        if ($found) {
                            $appUrl = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/');
                            $publicImageUrl = $appUrl . '/storage/' . ltrim($found, '/');
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed computing public image URL: ' . $e->getMessage());
            }

            // Provide image id if we have one (from upstream saved_images or local saved filename)
            $imageId = null;
            if (!empty($localSavedPath)) {
                $imageId = pathinfo($localSavedPath, PATHINFO_FILENAME);
            } else {
                // Try to extract id from tempImageUrl (/api/generation/image/{id})
                if (!empty($tempImageUrl) && str_contains($tempImageUrl, '/api/generation/image/')) {
                    $parts = explode('/api/generation/image/', $tempImageUrl);
                    $maybe = end($parts);
                    if (!empty($maybe)) $imageId = $maybe;
                }
            }

            

            // Provide 4 ready-to-publish caption options and choose a default (Option 1) so UI
            // can immediately publish without asking the user to select among options.
            $variants = [
                "¡Primeros pasos en el mundo de la programación! 💻✨ Emocionado/a por aprender y crear. #ProgramacionBasica #AprendeACodear #CodingLife #NuevasHabilidades",
                "¡Desbloqueando mi potencial con el curso de programación básica! 🚀🧠 ¡A programar se ha dicho! #ProgramacionParaTodos #CodingBeginner #Tecnologia #FuturoDigital",
                "¡Empezando mi viaje en la programación! 💡👨‍💻👩‍💻 #CursoDeProgramacion #Coding #Basico #Educacion",
                "¡Comenzando el curso de programación básica! ¿Alguien más por aquí aprendiendo a codear? 🤝💬 #ComunidadDeCoders #Programacion #AprenderJuntos #DesarrolloWeb",
            ];

            $default = $variants[0];

            return response()->json([
                'success' => true,
                'data' => [
                    'suggested_content' => $texto,
                    // pre-filled content the UI can use directly to publish
                    'default_suggestion' => $default,
                    // also provide all variants so UI can show alternatives if desired
                    'variants' => $variants,
                    // image generation details
                    'temp_image_url' => $tempImageUrl,
                    'proxy_image_url' => $tempImageUrl,
                    'public_image_url' => $publicImageUrl,
                    'image_generated' => !empty($tempImageUrl) || !empty($publicImageUrl),
                    // helpful for previewing in the UI (alias for front-end clarity)
                    'image_preview' => $publicImageUrl ?: $tempImageUrl,
                    'image_id' => $imageId,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('PostGenerator error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando borrador: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Proxy a generated image from the generative microservice so clients can fetch
     * previews from the marketing-backend host (useful when UI only knows marketing apiUrl).
     */
    public function proxyGeneratedImage($id)
    {
        // Serve locally saved generated images from storage/app/public/generated
        // This endpoint is public (no auth) so external services (e.g. Meta) can fetch images.
        $found = $this->findLocalGeneratedFile($id);
        if (! $found) {
            // Try to fetch on-demand from upstream generative service storage as a fallback.
            $genBase = rtrim(config('services.generative_api.url', 'http://127.0.0.1:8004'), '/');
            $tryExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $downloaded = null;
            foreach ($tryExt as $ext) {
                $tryUrl = $genBase . '/storage/images/' . $id . '.' . $ext;
                try {
                    $resp = Http::withOptions(['verify' => false])->get($tryUrl);
                } catch (\Throwable $e) {
                    $resp = null;
                }
                if ($resp && $resp->successful()) {
                    $contentType = $resp->header('Content-Type') ?? 'image/' . $ext;
                    $filename = $id . '.' . $ext;
                    $path = 'generated/' . $filename;
                    try {
                        Storage::disk('public')->put($path, $resp->body());
                        $found = $path;
                        $downloaded = true;
                        break;
                    } catch (\Throwable $e) {
                        Log::warning('Failed saving on-demand fetched image: ' . $e->getMessage());
                    }
                }
            }

            if (! $found) {
                return response()->json(['success' => false, 'message' => 'Image not found'], 404);
            }
        }

        try {
            $body = Storage::disk('public')->get($found);
            $fullPath = Storage::disk('public')->path($found);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $fullPath) : null;
            if ($finfo) { @finfo_close($finfo); }
            $mime = $mime ?? 'image/png';

            return response($body, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'public, max-age=31536000',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed serving local generated image: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to serve image'], 500);
        }
    }

    /**
     * Proxy text generation requests to the upstream generative service.
     * This allows the UI to call `/api/generation/{platform}` on the marketing
     * host and get the same response as calling the generative service directly.
     */
    public function proxyTextGeneration(Request $request, $platform)
    {
        $base = config('services.generative_api.url', 'http://127.0.0.1:8004');
        $endpoint = rtrim($base, '/') . '/api/generation/' . $platform;
        try {
            $resp = Http::timeout(60)->post($endpoint, $request->all());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Upstream request failed: ' . $e->getMessage()], 502);
        }

        return response($resp->body(), $resp->status(), $resp->headers());
    }

    /**
     * Proxy image generation requests to the upstream generative service.
     * Mirrors `/api/generation/image` so frontend can hit marketing host.
     * Also attempts to save any returned upstream saved_images locally so the
     * marketing `generation/image/{id}` proxy can serve them immediately.
     */
    public function proxyImageGeneration(Request $request)
    {
        $base = config('services.generative_api.url', 'http://127.0.0.1:8004');
        $endpoint = rtrim($base, '/') . '/api/generation/image';
        try {
            // Allow long-running image generation
            $resp = Http::timeout(300)->post($endpoint, $request->all());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Upstream image request failed: ' . $e->getMessage()], 502);
        }

        // Try to persist any returned saved image id locally
        try {
            if ($resp->ok()) {
                $payload = $resp->json();
                $saved = data_get($payload, 'saved_images.0');
                if ($saved && isset($saved['id'])) {
                    try {
                        $this->ensureLocalFromUpstreamId($saved['id'], $base);
                    } catch (\Throwable $e) {
                        // non-fatal
                        Log::warning('proxyImageGeneration: failed to ensure local copy: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('proxyImageGeneration: post-process error: ' . $e->getMessage());
        }

        return response($resp->body(), $resp->status(), $resp->headers());
    }

    /**
     * List generated images available in `storage/app/public/generated`.
     * Returns a JSON array with minimal metadata (id, url, size, last_modified).
     */
    public function listGeneratedImages()
    {
        try {
            $files = Storage::disk('public')->files('generated');
            $items = [];
            $appUrl = rtrim(config('app.url', env('APP_URL', 'http://127.0.0.1:8002')), '/');
            foreach ($files as $file) {
                $basename = pathinfo($file, PATHINFO_FILENAME);
                // Build public URL using APP_URL + /storage/<file>
                $url = $appUrl . '/storage/' . ltrim($file, '/');
                $size = null;
                $last = null;
                try {
                    $size = Storage::disk('public')->size($file);
                } catch (\Throwable $e) {
                    // ignore
                }
                try {
                    $last = Storage::disk('public')->lastModified($file);
                } catch (\Throwable $e) {
                    // ignore
                }
                $items[] = [
                    'id' => $basename,
                    'path' => $file,
                    'url' => $url,
                    'size' => $size,
                    'last_modified' => $last,
                ];
            }

            return response()->json(['success' => true, 'images' => $items]);
        } catch (\Throwable $e) {
            Log::warning('Failed listing generated images: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed listing images'], 500);
        }
    }

    /**
     * Try to find a locally saved generated file matching the upstream id.
     */
    private function findLocalGeneratedFile(string $id): ?string
    {
        $files = Storage::disk('public')->files('generated');
        foreach ($files as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            if ($base === $id) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Ensure the upstream id is downloaded and saved locally.
     */
    private function ensureLocalFromUpstreamId(string $upstreamId, string $upstreamBase): ?string
    {
        $existing = $this->findLocalGeneratedFile($upstreamId);
        if ($existing) {
            return $existing;
        }
        // First try the dedicated download endpoint
        $url = rtrim($upstreamBase, '/') . '/api/generation/image/' . $upstreamId;
        try {
            $resp = Http::withOptions(['verify' => false])->get($url);
        } catch (\Throwable $e) {
            Log::warning('Failed fetching upstream image for id ' . $upstreamId . ' via download endpoint: ' . $e->getMessage());
            $resp = null;
        }

        if ($resp && $resp->successful()) {
            $contentType = $resp->header('Content-Type') ?? 'image/png';
            $ext = $this->extensionFromContentType($contentType) ?? 'png';
            $filename = $upstreamId . '.' . $ext;
            $path = 'generated/' . $filename;

            try {
                Storage::disk('public')->put($path, $resp->body());
                return $path;
            } catch (\Throwable $e) {
                Log::warning('Failed saving upstream image locally: ' . $e->getMessage());
                return null;
            }
        }

        // Fallback: try the generative service public storage path directly
        $tryExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        foreach ($tryExt as $ext) {
            $tryUrl = rtrim($upstreamBase, '/') . '/storage/images/' . $upstreamId . '.' . $ext;
            try {
                $r2 = Http::withOptions(['verify' => false])->get($tryUrl);
            } catch (\Throwable $e) {
                $r2 = null;
            }
            if ($r2 && $r2->successful()) {
                $filename = $upstreamId . '.' . $ext;
                $path = 'generated/' . $filename;
                try {
                    Storage::disk('public')->put($path, $r2->body());
                    return $path;
                } catch (\Throwable $e) {
                    Log::warning('Failed saving upstream storage image locally: ' . $e->getMessage());
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Download a URL and save it in public/generated using a deterministic id.
     */
    private function ensureLocalDownloadedFromUrl(string $url): ?string
    {
        $id = md5($url);
        $existing = $this->findLocalGeneratedFile($id);
        if ($existing) {
            return $existing;
        }

        try {
            $resp = Http::withOptions(['verify' => false])->get($url);
        } catch (\Throwable $e) {
            Log::warning('Failed downloading image from raw URL: ' . $e->getMessage());
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        $contentType = $resp->header('Content-Type') ?? 'image/png';
        $ext = $this->extensionFromContentType($contentType) ?? 'png';
        $filename = $id . '.' . $ext;
        $path = 'generated/' . $filename;

        try {
            Storage::disk('public')->put($path, $resp->body());
            return $path;
        } catch (\Throwable $e) {
            Log::warning('Failed saving downloaded image locally: ' . $e->getMessage());
            return null;
        }
    }

    private function extensionFromContentType(string $contentType): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'image/bmp' => 'bmp',
        ];
        return $map[$contentType] ?? null;
    }
}
