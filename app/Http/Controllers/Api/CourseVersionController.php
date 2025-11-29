<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use IncadevUns\CoreDomain\Models\CourseVersion;
use IncadevUns\CoreDomain\Models\Group;
use IncadevUns\CoreDomain\Models\Enrollment;
use IncadevUns\CoreDomain\Models\ClassSession;
use IncadevUns\CoreDomain\Models\Attendance;
use IncadevUns\CoreDomain\Enums\AttendanceStatus;
use IncadevUns\CoreDomain\Models\Certificate;
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

        // Agregar métricas de estudiantes de esta versión
        $metricasEstudiantes = $this->obtenerMetricasEstudiantesVersion($id);
        $gruposActivos = $this->obtenerGruposActivos($id);

        $versionData = $version->toArray();
        $versionData['metricas_estudiantes'] = $metricasEstudiantes;
        $versionData['grupos_activos'] = $gruposActivos;

        return response()->json($versionData);
    }

    /**
     * Obtener métricas de estudiantes de una versión específica
     */
    private function obtenerMetricasEstudiantesVersion(string $versionId): array
    {
        $groupIds = Group::where('course_version_id', $versionId)->pluck('id');

        if ($groupIds->isEmpty()) {
            return [
                'total_matriculados' => 0,
                'activos' => 0,
                'completados' => 0,
                'reprobados' => 0,
                'desertores' => 0,
                'promedio_asistencia' => 0,
                'promedio_notas' => 0,
                'tasa_retencion' => 0,
                'tasa_graduacion' => 0,
            ];
        }

        $totalMatriculados = Enrollment::whereIn('group_id', $groupIds)->count();
        $activos = Enrollment::whereIn('group_id', $groupIds)->where('academic_status', 'active')->count();
        $completados = Enrollment::whereIn('group_id', $groupIds)->where('academic_status', 'completed')->count();
        $reprobados = Enrollment::whereIn('group_id', $groupIds)->where('academic_status', 'failed')->count();
        $desertores = Enrollment::whereIn('group_id', $groupIds)->where('academic_status', 'dropped')->count();

        // Detalle de asistencias y promedio (porcentaje)
        $detalleAsistencias = $this->calcularDetalleAsistencias($groupIds->toArray());
        $promedioAsistencia = $detalleAsistencias['porcentaje'] ?? 0;

        $promedioNotas = $this->calcularPromedioNotas($groupIds->toArray());

        // Contar certificados (egresados) vinculados a los usuarios de esta versión
        $userIds = Enrollment::whereIn('group_id', $groupIds)->pluck('user_id');
        $egresados = $userIds->isNotEmpty()
            ? Certificate::whereIn('user_id', $userIds)->count()
            : 0;

        $tasaRetencion = $totalMatriculados > 0
            ? (($activos + $completados) / $totalMatriculados) * 100
            : 0;

        $tasaGraduacion = $totalMatriculados > 0
            ? ($completados / $totalMatriculados) * 100
            : 0;

        return [
            'total_matriculados' => $totalMatriculados,
            'activos' => $activos,
            'completados' => $completados,
            'reprobados' => $reprobados,
            'desertores' => $desertores,
            'promedio_asistencia' => round($promedioAsistencia, 2),
            'promedio_notas' => round($promedioNotas, 2),
            'tasa_retencion' => round($tasaRetencion, 2),
            'tasa_graduacion' => round($tasaGraduacion, 2),
            'egresados' => $egresados,
            'asistencias' => $detalleAsistencias,
        ];
    }

    /**
     * Obtener grupos activos de una versión
     */
    private function obtenerGruposActivos(string $versionId): array
    {
        $grupos = Group::with(['courseVersion'])
            ->where('course_version_id', $versionId)
            ->where('status', 'active')
            ->get();

        return $grupos->map(function ($grupo) {
            $totalEstudiantes = Enrollment::where('group_id', $grupo->id)->count();

            // No existe el modelo Session ni un campo de estado; usamos ClassSession y
            // consideramos realizadas las que ya terminaron (end_time <= ahora)
            $totalClases = ClassSession::where('group_id', $grupo->id)->count();
            $clasesRealizadas = ClassSession::where('group_id', $grupo->id)
                ->where('end_time', '<=', now())
                ->count();
            $progreso = $totalClases > 0 ? ($clasesRealizadas / $totalClases) * 100 : 0;

            return [
                'grupo_id' => $grupo->id,
                'nombre' => $grupo->name,
                'version' => $grupo->courseVersion->name ?? 'N/A',
                'estudiantes' => $totalEstudiantes,
                'promedio_asistencia' => $this->calcularDetalleAsistencias([$grupo->id])['porcentaje'] ?? 0,
                'progreso' => round($progreso, 2),
                'total_clases' => $totalClases,
                'clases_realizadas' => $clasesRealizadas,
            ];
        })->toArray();
    }

    /**
     * Calcular promedio de asistencia de grupos
     */
    private function calcularDetalleAsistencias(array $groupIds): array
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
        $porcentaje = $asistenciasEsperadas > 0
            ? round(($asistenciasValidas / $asistenciasEsperadas) * 100, 2)
            : 0.0;

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
     * Calcular promedio de notas de grupos
     */
    private function calcularPromedioNotas(array $groupIds): float
    {
        if (empty($groupIds)) return 0;

        $enrollmentIds = \IncadevUns\CoreDomain\Models\Enrollment::whereIn('group_id', $groupIds)->pluck('id');
        if ($enrollmentIds->isEmpty()) return 0;

        return \IncadevUns\CoreDomain\Models\Grade::whereIn('enrollment_id', $enrollmentIds)
            ->avg('grade') ?? 0;
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
