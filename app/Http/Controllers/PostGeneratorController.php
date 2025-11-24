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
        ]);

        $prompt = $request->input('prompt');
        $platform = $request->input('platform');

        // Base URL for the generative microservice (adjust if different env)
        $base = config('services.generative_api.url', 'http://127.0.0.1:8004');

        try {
            $responses = Http::pool(function ($pool) use ($base, $prompt, $platform) {
                $textoEndpoint = rtrim($base, '/') . '/api/v1/marketing/generation/' . $platform;
                $imagenEndpoint = rtrim($base, '/') . '/api/v1/marketing/generation/image';

                return [
                    $pool->as('text')->post($textoEndpoint, [
                        'prompt' => $prompt,
                    ]),
                    $pool->as('image')->post($imagenEndpoint, [
                        'prompt' => $prompt,
                        'sampleCount' => 1
                    ]),
                ];
            });

            // Process text response (the microservice returns payload with candidates)
            $texto = 'No se pudo generar el texto.';
            if (isset($responses['text'])) {
                $r = $responses['text'];
                if ($r instanceof \Throwable) {
                    Log::warning('Text generation failed: ' . $r->getMessage());
                } elseif ($r->ok()) {
                    $payload = $r->json();
                    $texto = data_get($payload, 'payload.candidates.0.content.parts.0.text') ?? data_get($payload, 'text') ?? data_get($payload, 'generated_text') ?? $texto;
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
