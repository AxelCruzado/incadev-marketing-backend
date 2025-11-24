<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            $responses = Http::pool(function ($pool) use ($base, $prompt, $platform, $contentType, $linkUrl) {
                $textoEndpoint = rtrim($base, '/') . '/api/v1/marketing/generation/' . $platform;
                $requests = [];

                // Always request the generated text
                $requests[] = $pool->as('text')->post($textoEndpoint, [
                    'prompt' => $prompt,
                    'content_type' => $contentType,
                    'link_url' => $linkUrl,
                ]);

                // Only request an image when the requested content type is image
                if ($contentType === 'image') {
                    $imagenEndpoint = rtrim($base, '/') . '/api/v1/marketing/generation/image';
                    $requests[] = $pool->as('image')->post($imagenEndpoint, [
                        'prompt' => $prompt,
                        'sampleCount' => 1
                    ]);
                }

                return $requests;
            });

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

            $tempImageUrl = null;
            if (isset($responses['image'])) {
                $r = $responses['image'];
                if ($r instanceof \Throwable) {
                    Log::warning('Image generation failed: ' . $r->getMessage());
                } elseif ($r->ok()) {
                    $payload = $r->json();
                    $saved = data_get($payload, 'saved_images.0');
                    if ($saved && isset($saved['id'])) {
                        $tempImageUrl = rtrim($base, '/') . '/api/v1/marketing/generation/image/' . $saved['id'];
                    } else {
                        $tempImageUrl = data_get($payload, 'image_url') ?? data_get($payload, 'url') ?? null;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'suggested_content' => $texto,
                    'temp_image_url' => $tempImageUrl,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('PostGenerator error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando borrador: ' . $e->getMessage()], 500);
        }
    }
}
