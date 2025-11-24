<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Metric;

class MetricController extends Controller
{
    /**
     * Listar todas las métricas con filtros opcionales:
     * - ?post_id=10
     * - ?platform=facebook
     * - ?metric_type=daily
     */
    public function index(Request $request)
    {
        $query = Metric::with(['post']);

        if ($request->has('post_id')) {
            $query->where('post_id', $request->post_id);
        }

        if ($request->has('platform')) {
            $query->platform($request->platform);
        }

        if ($request->has('metric_type')) {
            $query->metricType($request->metric_type);
        }

        return $query->get();
    }

    /**
     * Crear métrica para un post.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'post_id'           => 'required|integer|exists:posts,id',
            'platform'          => 'required|string|in:facebook,instagram',
            'meta_post_id'      => 'nullable|string|max:255',
            'views'             => 'nullable|integer',
            'likes'             => 'nullable|integer',
            'comments'          => 'nullable|integer',
            'shares'            => 'nullable|integer',
            'engagement'        => 'nullable|integer',
            'reach'             => 'nullable|integer',
            'impressions'       => 'nullable|integer',
            'saves'             => 'nullable|integer',
            'metric_date'       => 'nullable|date',
            'metric_type'       => 'required|string|in:daily,weekly,monthly,cumulative',
        ]);

        $metric = Metric::create($validated);

        // El engagement se calcula automáticamente en el modelo (boot method)
        
        return response()->json($metric, 201);
    }

    /**
     * Detalle de una métrica.
     */
    public function show($id)
    {
        return Metric::with(['post'])->findOrFail($id);
    }

    /**
     * Actualizar métrica.
     */
    public function update(Request $request, $id)
    {
        $metric = Metric::findOrFail($id);

        $validated = $request->validate([
            'views'             => 'nullable|integer',
            'likes'             => 'nullable|integer',
            'comments'          => 'nullable|integer',
            'shares'            => 'nullable|integer',
            'reach'             => 'nullable|integer',
            'impressions'       => 'nullable|integer',
            'saves'             => 'nullable|integer',
            'metric_date'       => 'nullable|date',
            'metric_type'       => 'sometimes|string|in:daily,weekly,monthly,cumulative',
        ]);

        $metric->update($validated);

        // Recalcular engagement automáticamente
        $metric->engagement = $metric->calculateEngagement();
        $metric->save();

        return response()->json($metric);
    }

    /**
     * Eliminar.
     */
    public function destroy($id)
    {
        $metric = Metric::findOrFail($id);
        $metric->delete();

        return response()->json(['message' => 'Metric deleted']);
    }
}