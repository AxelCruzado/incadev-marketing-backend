<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Metric;

class MetricController extends Controller
{
    // Listar todas las métricas
    public function index()
    {
        return Metric::with('post')->get();
    }

    // Crear una nueva métrica
    public function store(Request $request)
    {
        $validated = $request->validate([
            'post_id'               => 'required|integer|exists:posts,id',
            'messages_received'     => 'nullable|integer',
            'pre_registrations'     => 'nullable|integer',
            'intention_percentage'  => 'nullable|numeric',
            'total_reach'           => 'nullable|integer',
            'total_interactions'    => 'nullable|integer',
            'ctr_percentage'        => 'nullable|numeric',
            'likes'                 => 'nullable|integer',
            'comments'              => 'nullable|integer',
            'private_messages'      => 'nullable|integer',
            'expected_enrollments'  => 'nullable|integer',
            'cpa_cost'              => 'nullable|numeric'
        ]);

        $metric = Metric::create($validated);

        return response()->json($metric, 201);
    }

    // Mostrar una métrica específica
    public function show($id)
    {
        return Metric::with('post')->findOrFail($id);
    }

    // Actualizar una métrica
    public function update(Request $request, $id)
    {
        $metric = Metric::findOrFail($id);

        $validated = $request->validate([
            'messages_received'     => 'nullable|integer',
            'pre_registrations'     => 'nullable|integer',
            'intention_percentage'  => 'nullable|numeric',
            'total_reach'           => 'nullable|integer',
            'total_interactions'    => 'nullable|integer',
            'ctr_percentage'        => 'nullable|numeric',
            'likes'                 => 'nullable|integer',
            'comments'              => 'nullable|integer',
            'private_messages'      => 'nullable|integer',
            'expected_enrollments'  => 'nullable|integer',
            'cpa_cost'              => 'nullable|numeric'
        ]);

        $metric->update($validated);

        return response()->json($metric);
    }

    // Eliminar una métrica
    public function destroy($id)
    {
        $metric = Metric::findOrFail($id);
        $metric->delete();

        return response()->json(['message' => 'Metric deleted']);
    }
}
