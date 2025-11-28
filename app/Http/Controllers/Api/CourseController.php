<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use IncadevUns\CoreDomain\Models\Course;
use IncadevUns\CoreDomain\Models\CourseVersion;
use IncadevUns\CoreDomain\Models\Campaign;
use IncadevUns\CoreDomain\Models\Group;
use IncadevUns\CoreDomain\Models\Enrollment;
use IncadevUns\CoreDomain\Models\ClassSession;
use IncadevUns\CoreDomain\Models\Attendance;
use IncadevUns\CoreDomain\Models\Certificate;
use IncadevUns\CoreDomain\Enums\AttendanceStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Obtener métricas de estudiantes del curso
        $metricasEstudiantes = $this->obtenerMetricasEstudiantes($id);

        // Obtener grupos activos del curso
        $gruposActivos = $this->obtenerGruposActivos($id);

        return response()->json([
            'id' => $course->id,
            'name' => $course->name,
            'description' => $course->description,
            'image_path' => $course->image_path,
            'created_at' => $course->created_at,
            'updated_at' => $course->updated_at,
            'versions' => $course->versions,
            'metricas_estudiantes' => $metricasEstudiantes,
            'grupos_activos' => $gruposActivos,
        ]);
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

    /**
     * Obtener métricas agregadas de estudiantes del curso
     */
    private function obtenerMetricasEstudiantes(string $courseId): array
    {
        // Obtener todas las versiones del curso
        $versionIds = CourseVersion::where('course_id', $courseId)->pluck('id');

        // Obtener todos los grupos de esas versiones
        $groupIds = Group::whereIn('course_version_id', $versionIds)->pluck('id');

        // Enrollments de esos grupos
        $enrollments = Enrollment::whereIn('group_id', $groupIds);
        $userIds = (clone $enrollments)->pluck('user_id');

        $totalMatriculados = $enrollments->count();
        $activos = (clone $enrollments)->where('academic_status', 'active')->count();
        $completados = (clone $enrollments)->where('academic_status', 'completed')->count();
        $reprobados = (clone $enrollments)->where('academic_status', 'failed')->count();
        $desertores = (clone $enrollments)->where('academic_status', 'dropped')->count();

        // Calcular promedio de asistencia del curso
        $promedioAsistencia = $this->calcularPromedioAsistenciaCurso($groupIds->toArray());
        $detalleAsistencias = $this->calcularDetalleAsistenciasCurso($groupIds->toArray());

        // Calcular promedio de notas del curso
        $promedioNotas = $this->calcularPromedioNotasCurso($enrollments->pluck('id'));

        // Contar certificados (egresados)
        $egresados = $userIds->isNotEmpty()
            ? Certificate::whereIn('user_id', $userIds)->count()
            : 0;

        // Calcular tasa de retención
        $tasaRetencion = $totalMatriculados > 0
            ? round((($activos + $completados) / $totalMatriculados) * 100, 1)
            : 0;

        // Calcular tasa de graduación
        $tasaGraduacion = $totalMatriculados > 0
            ? round(($completados / $totalMatriculados) * 100, 1)
            : 0;

        return [
            'total_matriculados' => $totalMatriculados,
            'activos' => $activos,
            'completados' => $completados,
            'reprobados' => $reprobados,
            'desertores' => $desertores,
            'egresados' => $egresados,
            'promedio_asistencia' => $promedioAsistencia,
            'promedio_notas' => $promedioNotas,
            'tasa_retencion' => $tasaRetencion,
            'tasa_graduacion' => $tasaGraduacion,
            'asistencias' => $detalleAsistencias,
        ];
    }

    /**
     * Obtener información de grupos activos del curso
     */
    private function obtenerGruposActivos(string $courseId): array
    {
        // Obtener todas las versiones del curso
        $versionIds = CourseVersion::where('course_id', $courseId)->pluck('id');

        // Obtener grupos activos
        $grupos = Group::whereIn('course_version_id', $versionIds)
            ->whereIn('status', ['active', 'enrolling'])
            ->with(['courseVersion'])
            ->get();

        return $grupos->map(function ($grupo) {
            // Contar estudiantes del grupo
            $totalEstudiantes = Enrollment::where('group_id', $grupo->id)->count();

            // Calcular promedio de asistencia del grupo
            $promedioAsistencia = $this->calcularPromedioAsistenciaGrupo($grupo->id);

            // Calcular progreso del grupo (basado en clases realizadas vs totales)
            $totalClases = ClassSession::where('group_id', $grupo->id)->count();
            $clasesRealizadas = ClassSession::where('group_id', $grupo->id)
                ->where('end_time', '<', now())
                ->count();

            $progreso = $totalClases > 0
                ? round(($clasesRealizadas / $totalClases) * 100, 1)
                : 0;

            return [
                'grupo_id' => $grupo->id,
                'nombre' => $grupo->name ?? 'Grupo sin nombre',
                'version' => $grupo->courseVersion->name ?? 'Sin versión',
                'estudiantes' => $totalEstudiantes,
                'promedio_asistencia' => $promedioAsistencia,
                'progreso' => $progreso,
                'total_clases' => $totalClases,
                'clases_realizadas' => $clasesRealizadas,
            ];
        })->toArray();
    }

    /**
     * Calcular promedio de asistencia para un curso completo
     */
    private function calcularPromedioAsistenciaCurso(array $groupIds): float
    {
        if (empty($groupIds)) {
            return 0.0;
        }

        $totalClases = ClassSession::whereIn('group_id', $groupIds)->count();

        if ($totalClases === 0) {
            return 0.0;
        }

        // Obtener todos los enrollments de esos grupos
        $enrollmentIds = Enrollment::whereIn('group_id', $groupIds)->pluck('id');

        // Contar asistencias válidas (present + late)
        $asistenciasValidas = Attendance::whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
            ->count();

        // Total de asistencias esperadas
        $totalEstudiantes = $enrollmentIds->count();
        $asistenciasEsperadas = $totalClases * $totalEstudiantes;

        if ($asistenciasEsperadas === 0) {
            return 0.0;
        }

        return round(($asistenciasValidas / $asistenciasEsperadas) * 100, 2);
    }

    /**
     * Calcular detalle de asistencias para un curso completo
     */
    private function calcularDetalleAsistenciasCurso(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [
                'total_clases' => 0,
                'total_estudiantes' => 0,
                'esperadas' => 0,
                'presentes' => 0,
                'tardanzas' => 0,
                'ausentes' => 0,
                'justificados' => 0,
                'porcentaje' => 0.0,
            ];
        }

        $totalClases = ClassSession::whereIn('group_id', $groupIds)->count();
        $enrollmentIds = Enrollment::whereIn('group_id', $groupIds)->pluck('id');

        $totalEstudiantes = $enrollmentIds->count();
        $asistenciasEsperadas = $totalClases * $totalEstudiantes;

        if ($asistenciasEsperadas === 0) {
            return [
                'total_clases' => $totalClases,
                'total_estudiantes' => $totalEstudiantes,
                'esperadas' => 0,
                'presentes' => 0,
                'tardanzas' => 0,
                'ausentes' => 0,
                'justificados' => 0,
                'porcentaje' => 0.0,
            ];
        }

        $presentes = Attendance::whereIn('enrollment_id', $enrollmentIds)
            ->where('status', AttendanceStatus::Present)
            ->count();
        $tardanzas = Attendance::whereIn('enrollment_id', $enrollmentIds)
            ->where('status', AttendanceStatus::Late)
            ->count();
        $ausentes = Attendance::whereIn('enrollment_id', $enrollmentIds)
            ->where('status', AttendanceStatus::Absent)
            ->count();
        $justificados = Attendance::whereIn('enrollment_id', $enrollmentIds)
            ->where('status', AttendanceStatus::Excused)
            ->count();

        $asistenciasValidas = $presentes + $tardanzas;
        $porcentaje = round(($asistenciasValidas / $asistenciasEsperadas) * 100, 2);

        return [
            'total_clases' => $totalClases,
            'total_estudiantes' => $totalEstudiantes,
            'esperadas' => $asistenciasEsperadas,
            'presentes' => $presentes,
            'tardanzas' => $tardanzas,
            'ausentes' => $ausentes,
            'justificados' => $justificados,
            'porcentaje' => $porcentaje,
        ];
    }

    /**
     * Calcular promedio de asistencia para un grupo específico
     */
    private function calcularPromedioAsistenciaGrupo(int $groupId): float
    {
        $totalClases = ClassSession::where('group_id', $groupId)->count();

        if ($totalClases === 0) {
            return 0.0;
        }

        // Obtener enrollments del grupo
        $enrollmentIds = Enrollment::where('group_id', $groupId)->pluck('id');

        // Contar asistencias válidas
        $asistenciasValidas = Attendance::whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
            ->count();

        // Total esperado
        $totalEstudiantes = $enrollmentIds->count();
        $asistenciasEsperadas = $totalClases * $totalEstudiantes;

        if ($asistenciasEsperadas === 0) {
            return 0.0;
        }

        return round(($asistenciasValidas / $asistenciasEsperadas) * 100, 2);
    }

    /**
     * Calcular promedio de notas para un conjunto de enrollments
     */
    private function calcularPromedioNotasCurso($enrollmentIds): float
    {
        if ($enrollmentIds->isEmpty()) {
            return 0.0;
        }

        // Obtener todas las notas de esos enrollments
        $promedio = DB::table('grades')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->avg('grade');

        return $promedio ? round($promedio, 2) : 0.0;
    }
}
