<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Campaign;

class CampaignController extends Controller
{
    /**
     * Listar todas las campañas
     */
    public function index(Request $request)
    {
        $query = Campaign::with(['proposal', 'courseVersion']);

        if ($request->has('proposal_id')) {
            $query->where('proposal_id', $request->proposal_id);
        }
        if ($request->has('course_version_id')) {
            $query->where('course_version_id', $request->course_version_id);
        }

        return response()->json($query->get());
    }

    /**
     * Crear una nueva campaña
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'objective'         => 'nullable|string',
            'proposal_id'       => 'nullable|exists:proposals,id',
            'course_version_id' => 'nullable|exists:course_versions,id',
            'start_date'        => 'nullable|date',
            'end_date'          => 'nullable|date|after_or_equal:start_date',
        ]);

        $campaign = Campaign::create($validated);

        return response()->json($campaign->load(['proposal', 'courseVersion']), 201);
    }

    /**
     * Mostrar una campaña
     */
    public function show(string $id)
    {
        $campaign = Campaign::with(['proposal', 'courseVersion'])->findOrFail($id);
        return response()->json($campaign);
    }

    /**
     * Actualizar una campaña
     */
    public function update(Request $request, string $id)
    {
        $campaign = Campaign::findOrFail($id);

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'objective'         => 'nullable|string',
            'proposal_id'       => 'nullable|exists:proposals,id',
            'course_version_id' => 'nullable|exists:course_versions,id',
            'start_date'        => 'nullable|date',
            'end_date'          => 'nullable|date|after_or_equal:start_date',
        ]);

        $campaign->update($validated);

        return response()->json($campaign->load(['proposal', 'courseVersion']));
    }

    /**
     * Eliminar una campaña
     */
    public function destroy(string $id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted successfully']);
    }

    /**
     * Obtener métricas agregadas de una campaña
     * Suma todas las métricas de todos los posts
     */
    public function metrics(string $id)
    {
        $campaign = Campaign::with(['posts.metrics'])->findOrFail($id);

        // Aplanar todas las métricas de todos los posts
        $allMetrics = $campaign->posts->flatMap(function ($post) {
            return $post->metrics;
        });

        // Calcular totales y promedios
        $metricsSummary = [
            'total_messages_received' => $allMetrics->sum('messages_received'),
            'total_pre_registrations' => $allMetrics->sum('pre_registrations'),
            'average_intention_percentage' => $allMetrics->avg('intention_percentage') ?? 0,
            'total_reach' => $allMetrics->sum('reach'),
            'total_interactions' => $allMetrics->sum('engagement'),
            'average_ctr_percentage' => $allMetrics->avg('ctr_percentage') ?? 0,
            'total_likes' => $allMetrics->sum('likes'),
            'total_comments' => $allMetrics->sum('comments'),
            'total_private_messages' => $allMetrics->sum('private_messages'),
            'expected_enrollments' => $allMetrics->sum('expected_enrollments'),
            'average_cpa_cost' => $allMetrics->avg('cpa_cost') ?? 0,
        ];

        // Métricas por post (suma de todas sus métricas)
        $postsMetrics = $campaign->posts->map(function ($post) {
            $postMetrics = $post->metrics;
            
            return [
                'post_id' => $post->id,
                'platform' => $post->platform,
                'messages_received' => $postMetrics->sum('messages_received'),
                'pre_registrations' => $postMetrics->sum('pre_registrations'),
                'intention_percentage' => $postMetrics->avg('intention_percentage') ?? 0,
                'total_reach' => $postMetrics->sum('reach'),
                'total_interactions' => $postMetrics->sum('engagement'),
                'ctr_percentage' => $postMetrics->avg('ctr_percentage') ?? 0,
                'likes' => $postMetrics->sum('likes'),
                'comments' => $postMetrics->sum('comments'),
                'private_messages' => $postMetrics->sum('private_messages'),
                'expected_enrollments' => $postMetrics->sum('expected_enrollments'),
                'cpa_cost' => $postMetrics->avg('cpa_cost') ?? 0,
            ];
        });

        return response()->json([
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'metrics_summary' => $metricsSummary,
            'posts_metrics' => $postsMetrics
        ]);
    }
}