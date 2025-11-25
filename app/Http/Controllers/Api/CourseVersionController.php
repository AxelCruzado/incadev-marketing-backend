<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use IncadevUns\CoreDomain\Models\CourseVersion;
use Illuminate\Http\Request;

class CourseVersionController extends Controller
{
    /**
     * Listar todas las versiones de todos los cursos
     * GET /api/versions
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        $versions = CourseVersion::with(['course'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($versions);
    }

    public function show(string $id)
    {
        $version = CourseVersion::with(['course', 'campaigns.posts.metrics'])
            ->findOrFail($id);

        return response()->json($version);
    }

    public function campaigns(string $id)
{
    $version = CourseVersion::findOrFail($id);

    $campaigns = $version->campaigns()
        ->with(['proposal', 'posts.metrics'])
        ->get();

    return response()->json([
        'version' => [
            'id' => $version->id,
            'name' => $version->name,
            'version' => $version->version,
            'price' => $version->price,
            'status' => $version->status,
        ],
        'total_campaigns' => $campaigns->count(),
        'campaigns' => $campaigns
    ]);
}

}
