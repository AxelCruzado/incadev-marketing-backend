<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Proposal;

class ProposalController extends Controller
{
    /**
     * Listar todas las propuestas
     */
    public function index()
    {
        $proposals = Proposal::orderBy('id', 'desc')->get();
        return response()->json($proposals);
    }

    /**
     * Crear una nueva propuesta
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'required|string',
            'area'            => 'required|string|max:255',
            'priority'        => 'required|in:bajo,medio,alto',
            'status'          => 'nullable|in:borrador,activa,pausada,aprobada,rechazada',
            'target_audience' => 'required|in:principiantes,intermedios,avanzados,profesionales',
            'created_by'      => 'nullable|exists:users,id'
        ]);

        $proposal = Proposal::create($request->all());

        return response()->json([
            'message' => 'Propuesta creada correctamente',
            'data' => $proposal
        ], 201);
    }

    /**
     * Mostrar una propuesta por ID
     */
    public function show(string $id)
    {
        $proposal = Proposal::find($id);

        if (!$proposal) {
            return response()->json(["message" => "Propuesta no encontrada"], 404);
        }

        return response()->json($proposal);
    }

    /**
     * Actualizar una propuesta
     */
    public function update(Request $request, string $id)
    {
        $proposal = Proposal::find($id);

        if (!$proposal) {
            return response()->json(["message" => "Propuesta no encontrada"], 404);
        }

        $request->validate([
            'title'           => 'string|max:255',
            'description'     => 'string',
            'area'            => 'string|max:255',
            'priority'        => 'in:bajo,medio,alto',
            'status'          => 'in:borrador,activa,pausada,aprobada,rechazada',
            'target_audience' => 'in:principiantes,intermedios,avanzados,profesionales',
            'created_by'      => 'nullable|exists:users,id'
        ]);

        $proposal->update($request->all());

        return response()->json([
            'message' => 'Propuesta actualizada correctamente',
            'data' => $proposal
        ]);
    }

    /**
     * Eliminar una propuesta
     */
    public function destroy(string $id)
    {
        $proposal = Proposal::find($id);

        if (!$proposal) {
            return response()->json(["message" => "Propuesta no encontrada"], 404);
        }

        $proposal->delete();

        return response()->json([
            'message' => 'Propuesta eliminada correctamente'
        ]);
    }
}
