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
    public function index()
    {
        return Campaign::all();
    }

    /**
     * Crear una nueva campaña
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'required|string',
        ]);

        $campaign = Campaign::create($validated);

        return response()->json($campaign, 201);
    }

    /**
     * Mostrar una campaña
     */
    public function show(string $id)
    {
        return Campaign::findOrFail($id);
    }

    /**
     * Actualizar una campaña
     */
    public function update(Request $request, string $id)
    {
        $campaign = Campaign::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'sometimes|string',
        ]);

        $campaign->update($validated);

        return response()->json($campaign);
    }

    /**
     * Eliminar una campaña
     */
    public function destroy(string $id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted']);
    }
}
