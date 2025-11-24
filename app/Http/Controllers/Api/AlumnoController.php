<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use IncadevUns\CoreDomain\Models\Enrollment;
use IncadevUns\CoreDomain\Models\Certificate;
use IncadevUns\CoreDomain\Models\Group;

class AlumnoController extends Controller
{
    /**
     * Obtener estadísticas de alumnos para el dashboard de marketing
     * GET /api/alumnos/stats
     */
    public function stats(): JsonResponse
    {
        // Contar por cada estado de enrollment
        $pendientes = Enrollment::where('academic_status', 'pending')->count();
        $cursando = Enrollment::where('academic_status', 'active')->count();
        $completados = Enrollment::where('academic_status', 'completed')->count();
        $reprobados = Enrollment::where('academic_status', 'failed')->count();
        $desertores = Enrollment::where('academic_status', 'dropped')->count();

        // Contar egresados (certificados emitidos)
        $egresados = Certificate::count();

        // Total de matriculados (todos los enrollments)
        $totalMatriculados = Enrollment::count();

        return response()->json([
            'pendientes' => $pendientes,
            'cursando' => $cursando,
            'completados' => $completados,
            'reprobados' => $reprobados,
            'desertores' => $desertores,
            'egresados' => $egresados,
            'total_matriculados' => $totalMatriculados,
        ]);
    }

    /**
     * Obtener resumen completo de alumnos con tendencias
     * GET /api/alumnos/resumen
     */
    public function resumen(): JsonResponse
    {
        // Estadísticas actuales
        $matriculados = Enrollment::where('academic_status', 'active')->count();
        $inactivos = Enrollment::where('academic_status', 'dropped')->count();
        $egresados = Certificate::count();
        $pendientes = Enrollment::where('academic_status', 'pending')->count();
        $completados = Enrollment::where('academic_status', 'completed')->count();

        // Grupos activos (en curso)
        $gruposActivos = Group::where('status', 'active')->count();

        // Grupos en inscripción
        $gruposEnrolling = Group::where('status', 'enrolling')->count();

        return response()->json([
            'estadisticas' => [
                'matriculados' => $matriculados,
                'inactivos' => $inactivos,
                'egresados' => $egresados,
                'pendientes' => $pendientes,
                'completados' => $completados,
            ],
            'grupos' => [
                'activos' => $gruposActivos,
                'en_inscripcion' => $gruposEnrolling,
            ],
            'total_estudiantes' => $matriculados + $pendientes,
        ]);
    }
}
