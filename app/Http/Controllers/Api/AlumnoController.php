<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use IncadevUns\CoreDomain\Models\Enrollment;
use IncadevUns\CoreDomain\Models\Certificate;
use IncadevUns\CoreDomain\Models\Group;
use IncadevUns\CoreDomain\Models\StudentProfile;

class AlumnoController extends Controller
{
    /**
     * Obtener estadísticas y lista de alumnos para marketing
     * GET /api/alumnos/stats
     */
    public function stats(Request $request): JsonResponse
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

        // Obtener lista de alumnos con su estado real
        $enrollments = Enrollment::with(['user', 'group.courseVersion.course'])
            ->get()
            ->map(function ($enrollment) {
                $user = $enrollment->user;
                if (!$user) return null;

                // Obtener el perfil del estudiante
                $studentProfile = StudentProfile::where('user_id', $user->id)->first();

                // Verificar si tiene certificado (egresado)
                $tieneCertificado = Certificate::where('user_id', $user->id)->exists();

                // Determinar estado
                $estado = $enrollment->academic_status->value ?? 'pending';
                if ($tieneCertificado && $estado === 'completed') {
                    $estado = 'egresado';
                }

                // Obtener nombre del curso
                $curso = 'Sin curso asignado';
                if ($enrollment->group && $enrollment->group->courseVersion && $enrollment->group->courseVersion->course) {
                    $curso = $enrollment->group->courseVersion->course->name;
                }

                return [
                    'id' => $user->id,
                    'nombre' => $user->fullname ?? $user->name,
                    'email' => $user->email,
                    'dni' => $user->dni ?? '',
                    'avatar' => $user->avatar,
                    'telefono' => $user->phone ?? 'No registrado',
                    'estado' => $estado,
                    'curso' => $curso,
                    'grupo_id' => $enrollment->group_id,
                    'fecha_registro' => $user->created_at?->toISOString(),
                    'ultima_actualizacion' => $user->updated_at?->toISOString(),
                    'interests' => $studentProfile->interests ?? [],
                    'learning_goal' => $studentProfile->learning_goal ?? '',
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'stats' => [
                'pendientes' => $pendientes,
                'cursando' => $cursando,
                'completados' => $completados,
                'reprobados' => $reprobados,
                'desertores' => $desertores,
                'egresados' => $egresados,
                'total_matriculados' => $totalMatriculados,
            ],
            'alumnos' => $enrollments,
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
