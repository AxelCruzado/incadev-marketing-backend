<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use IncadevUns\CoreDomain\Models\Post;

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
}