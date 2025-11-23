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
     */
    public function metrics(string $id)
    {
        $campaign = Campaign::with(['posts.metric'])->findOrFail($id);

        $metricsSummary = [
            'total_messages_received' => $campaign->posts->sum(fn($p) => $p->metric?->messages_received ?? 0),
            'total_pre_registrations' => $campaign->posts->sum(fn($p) => $p->metric?->pre_registrations ?? 0),
            'average_intention_percentage' => $campaign->posts->avg(fn($p) => $p->metric?->intention_percentage ?? 0),
            'total_reach' => $campaign->posts->sum(fn($p) => $p->metric?->total_reach ?? 0),
            'total_interactions' => $campaign->posts->sum(fn($p) => $p->metric?->total_interactions ?? 0),
            'average_ctr_percentage' => $campaign->posts->avg(fn($p) => $p->metric?->ctr_percentage ?? 0),
            'total_likes' => $campaign->posts->sum(fn($p) => $p->metric?->likes ?? 0),
            'total_comments' => $campaign->posts->sum(fn($p) => $p->metric?->comments ?? 0),
            'total_private_messages' => $campaign->posts->sum(fn($p) => $p->metric?->private_messages ?? 0),
            'expected_enrollments' => $campaign->posts->sum(fn($p) => $p->metric?->expected_enrollments ?? 0),
            'average_cpa_cost' => $campaign->posts->avg(fn($p) => $p->metric?->cpa_cost ?? 0),
        ];

        $postsMetrics = $campaign->posts->map(function ($post) {
            return [
                'post_id' => $post->id,
                'platform' => $post->platform,
                'messages_received' => $post->metric?->messages_received ?? 0,
                'pre_registrations' => $post->metric?->pre_registrations ?? 0,
                'intention_percentage' => $post->metric?->intention_percentage ?? 0,
                'total_reach' => $post->metric?->total_reach ?? 0,
                'total_interactions' => $post->metric?->total_interactions ?? 0,
                'ctr_percentage' => $post->metric?->ctr_percentage ?? 0,
                'likes' => $post->metric?->likes ?? 0,
                'comments' => $post->metric?->comments ?? 0,
                'private_messages' => $post->metric?->private_messages ?? 0,
                'expected_enrollments' => $post->metric?->expected_enrollments ?? 0,
                'cpa_cost' => $post->metric?->cpa_cost ?? 0,
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
