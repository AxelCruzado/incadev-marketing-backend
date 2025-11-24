<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use IncadevUns\CoreDomain\Models\Course;
use IncadevUns\CoreDomain\Models\CourseVersion;
use IncadevUns\CoreDomain\Models\Campaign;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    /**
     * Listar todos los cursos
     * GET /api/courses
     */
    public function index(Request $request)
    {
        $query = Course::with(['versions']);

        // Paginación opcional
        $perPage = $request->get('per_page', 15);

        if ($request->has('all') && $request->all == 'true') {
            return response()->json(Course::with(['versions'])->get());
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Mostrar detalle de un curso
     * GET /api/courses/{id}
     */
    public function show(string $id)
    {
        $course = Course::with(['versions'])->findOrFail($id);

        return response()->json($course);
    }

    /**
     * Obtener versiones de un curso específico
     * GET /api/courses/{id}/versions
     */
    public function versions(string $id)
    {
        $course = Course::findOrFail($id);

        $versions = CourseVersion::where('course_id', $id)
            ->with(['campaigns'])
            ->get();

        return response()->json([
            'course' => $course,
            'versions' => $versions
        ]);
    }

    /**
     * Obtener campañas relacionadas a un curso (a través de sus versiones)
     * GET /api/courses/{id}/campaigns
     */
    public function campaigns(string $id)
    {
        $course = Course::with(['versions'])->findOrFail($id);

        // Obtener IDs de todas las versiones del curso
        $versionIds = $course->versions->pluck('id');

        // Obtener campañas asociadas a esas versiones
        $campaigns = Campaign::whereIn('course_version_id', $versionIds)
            ->with(['proposal', 'courseVersion', 'posts.metrics'])
            ->get();

        // Calcular métricas agregadas por campaña
        $campaignsWithMetrics = $campaigns->map(function ($campaign) {
            // Sumar métricas de todos los posts (usando la última métrica de cada post)
            $totalReach = 0;
            $totalInteractions = 0;

            foreach ($campaign->posts as $post) {
                $latestMetric = $post->metrics->sortByDesc('created_at')->first();
                if ($latestMetric) {
                    $totalReach += $latestMetric->reach ?? 0;
                    $totalInteractions += ($latestMetric->likes ?? 0) + ($latestMetric->comments ?? 0) + ($latestMetric->shares ?? 0);
                }
            }

            // Calcular CTR como (engagement / reach) * 100
            $totalEngagement = 0;
            foreach ($campaign->posts as $post) {
                $latestMetric = $post->metrics->sortByDesc('created_at')->first();
                if ($latestMetric) {
                    $totalEngagement += $latestMetric->engagement ?? 0;
                }
            }
            $averageCtr = $totalReach > 0 ? ($totalEngagement / $totalReach) * 100 : 0;

            $metrics = [
                'total_posts' => $campaign->posts->count(),
                'total_reach' => $totalReach,
                'total_interactions' => $totalInteractions,
                'total_pre_registrations' => 0,
                'average_ctr' => round($averageCtr, 2),
            ];

            return [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'objective' => $campaign->objective,
                'start_date' => $campaign->start_date,
                'end_date' => $campaign->end_date,
                'proposal_id' => $campaign->proposal_id,
                'course_version_id' => $campaign->course_version_id,
                'course_version' => $campaign->courseVersion,
                'proposal' => $campaign->proposal,
                'created_at' => $campaign->created_at,
                'updated_at' => $campaign->updated_at,
                'metrics' => $metrics,
            ];
        });

        return response()->json([
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
                'description' => $course->description,
            ],
            'total_versions' => $course->versions->count(),
            'total_campaigns' => $campaigns->count(),
            'campaigns' => $campaignsWithMetrics
        ]);
    }
}
