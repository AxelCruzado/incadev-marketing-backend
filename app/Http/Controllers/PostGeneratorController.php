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

            // if the caller provided an image_url, prefer it as the preview
            $providedImageUrl = $request->input('image_url');
            $tempImageUrl = $providedImageUrl ?: null;
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
                    'image_generated' => !empty($tempImageUrl),
                    // helpful for previewing in the UI (alias for front-end clarity)
                    'image_preview' => $tempImageUrl,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('PostGenerator error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando borrador: ' . $e->getMessage()], 500);
        }
    }
}
