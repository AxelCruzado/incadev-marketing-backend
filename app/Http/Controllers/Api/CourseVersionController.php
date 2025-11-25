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
}
