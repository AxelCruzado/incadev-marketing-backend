<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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