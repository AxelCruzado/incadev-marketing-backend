<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use IncadevUns\CoreDomain\Models\Enrollment;
use IncadevUns\CoreDomain\Models\Certificate;
use IncadevUns\CoreDomain\Models\Group;
use IncadevUns\CoreDomain\Models\StudentProfile;
use IncadevUns\CoreDomain\Models\Attendance;
use IncadevUns\CoreDomain\Models\ClassSession;
use IncadevUns\CoreDomain\Models\Module;
use IncadevUns\CoreDomain\Enums\AttendanceStatus;
use Illuminate\Support\Facades\DB;

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
        $enrollments = Enrollment::with(['user', 'group.courseVersion.course', 'attendances', 'grades'])
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

                // Calcular estadísticas de asistencias
                $asistencias = $this->calcularAsistencias($enrollment);

                // Calcular estadísticas de rendimiento (notas)
                $rendimiento = $this->calcularRendimiento($enrollment);

                // Calcular engagement score
                $engagementScore = $this->calcularEngagementScore(
                    $asistencias['porcentaje'] ?? 0,
                    $rendimiento['promedio_notas'] ?? 0
                );

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
                    'asistencias' => $asistencias,
                    'rendimiento' => $rendimiento,
                    'engagement_score' => $engagementScore,
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

    /**
     * Obtener detalle completo de un alumno específico
     * GET /api/alumnos/{id}/detalle
     */
    public function detalle(string $id): JsonResponse
    {
        // Buscar enrollment del usuario (puede tener múltiples enrollments)
        $enrollments = Enrollment::where('user_id', $id)
            ->with([
                'user',
                'group.courseVersion.course',
                'attendances.classSession.module',
                'grades.exam'
            ])
            ->get();

        if ($enrollments->isEmpty()) {
            return response()->json([
                'error' => 'Alumno no encontrado'
            ], 404);
        }

        // Usar un enrollment activo si existe; si no, el mas reciente
        $enrollment = $enrollments->first(function ($enrollment) {
            $status = $enrollment->academic_status->value ?? $enrollment->academic_status;
            return $status === 'active';
        }) ?? $enrollments->sortByDesc('created_at')->first();
        $user = $enrollment->user;

        if (!$user) {
            return response()->json([
                'error' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener perfil del estudiante
        $studentProfile = StudentProfile::where('user_id', $user->id)->first();

        // Verificar si es egresado
        $certificate = Certificate::where('user_id', $user->id)->first();

        // Determinar estado principal
        $estado = $enrollment->academic_status->value ?? $enrollment->academic_status ?? 'pending';
        if ($certificate && $estado === 'completed') {
            $estado = 'egresado';
        }

        // Calcular engagement (0-10) igual que en stats
        $engagementScore = $this->calcularEngagementScore(
            $this->calcularAsistencias($enrollment)['porcentaje'] ?? 0,
            $this->calcularRendimiento($enrollment)['promedio_notas'] ?? 0
        );

        // Información básica del alumno
        $alumno = [
            'id' => $user->id,
            'nombre' => $user->fullname ?? $user->name,
            'email' => $user->email,
            'dni' => $user->dni ?? '',
            'avatar' => $user->avatar,
            'telefono' => $user->phone ?? 'No registrado',
            'curso' => $enrollment->group->courseVersion->course->name ?? 'Sin curso',
            'grupo' => $enrollment->group->name ?? 'Sin grupo',
            'grupo_id' => $enrollment->group_id,
            'estado' => $estado,
            'fecha_registro' => $user->created_at?->toISOString(),
            'interests' => $studentProfile->interests ?? [],
            'learning_goal' => $studentProfile->learning_goal ?? '',
            'engagement_score' => $engagementScore,
        ];

        // Historial de asistencias
        $historialAsistencias = $this->obtenerHistorialAsistencias($enrollment);

        // Historial de notas
        $historialNotas = $this->obtenerHistorialNotas($enrollment);

        // Progreso por módulos
        $progresoModulos = $this->obtenerProgresoModulos($enrollment);

        // Timeline de eventos
        $timeline = $this->obtenerTimeline($enrollment, $certificate);

        // Resumen de asistencias
        $resumenAsistencias = $this->calcularAsistencias($enrollment);

        // Resumen de rendimiento
        $resumenRendimiento = $this->calcularRendimiento($enrollment);

        // Engagement score (mismo cálculo que en listado)
        $engagementScore = $this->calcularEngagementScore(
            $resumenAsistencias['porcentaje'] ?? 0,
            $resumenRendimiento['promedio_notas'] ?? 0
        );

        return response()->json([
            'alumno' => $alumno,
            'asistencias' => [
                'resumen' => $resumenAsistencias,
                'historial' => $historialAsistencias,
            ],
            'rendimiento' => [
                'resumen' => $resumenRendimiento,
                'historial' => $historialNotas,
            ],
            'progreso_modulos' => $progresoModulos,
            'timeline' => $timeline,
            'certificado' => $certificate ? [
                'id' => $certificate->id,
                'fecha_emision' => $certificate->created_at?->toISOString(),
            ] : null,
            'engagement_score' => $engagementScore,
        ]);
    }

    /**
     * Calcular estadísticas de asistencias de un enrollment
     */
    private function calcularAsistencias(Enrollment $enrollment): ?array
    {
        // Obtener total de clases del grupo
        $totalClases = ClassSession::where('group_id', $enrollment->group_id)->count();

        if ($totalClases === 0) {
            return null;
        }

        // Contar asistencias por estado
        $attendances = $enrollment->attendances;

        $presentes = $attendances->where('status', AttendanceStatus::Present)->count();
        $tardanzas = $attendances->where('status', AttendanceStatus::Late)->count();
        $ausentes = $attendances->where('status', AttendanceStatus::Absent)->count();
        $justificados = $attendances->where('status', AttendanceStatus::Excused)->count();

        // Calcular porcentaje (presentes + tardanzas se consideran asistencia)
        $asistenciasValidas = $presentes + $tardanzas;
        $porcentaje = $totalClases > 0
            ? round(($asistenciasValidas / $totalClases) * 100, 2)
            : 0;

        return [
            'total_clases' => $totalClases,
            'presentes' => $presentes,
            'tardanzas' => $tardanzas,
            'ausentes' => $ausentes,
            'justificados' => $justificados,
            'porcentaje' => $porcentaje,
        ];
    }

    /**
     * Calcular estadísticas de rendimiento (notas) de un enrollment
     */
    private function calcularRendimiento(Enrollment $enrollment): ?array
    {
        $grades = $enrollment->grades;

        if ($grades->isEmpty()) {
            return null;
        }

        // Calcular promedio de notas
        $promedioNotas = round($grades->avg('grade'), 2);

        // Contar tareas/exámenes
        $tareasEntregadas = $grades->count();

        // Total de exámenes disponibles para el grupo (esto podría venir de otra tabla)
        // Por ahora usamos el count de grades como "entregadas"
        $tareasTotales = $tareasEntregadas; // Ajustar según lógica de negocio

        return [
            'promedio_notas' => $promedioNotas,
            'tareas_entregadas' => $tareasEntregadas,
            'tareas_totales' => $tareasTotales,
        ];
    }

    /**
     * Calcular engagement score (0-10)
     * Basado en asistencia (60%) y promedio de notas (40%)
     */
    private function calcularEngagementScore(float $porcentajeAsistencia, float $promedioNotas): float
    {
        // Normalizar promedio de notas (asumiendo escala de 0-100)
        $notasNormalizadas = min($promedioNotas, 100) / 100;

        // Normalizar porcentaje de asistencia
        $asistenciaNormalizada = min($porcentajeAsistencia, 100) / 100;

        // 60% peso asistencia, 40% peso notas
        $score = ($asistenciaNormalizada * 6) + ($notasNormalizadas * 4);

        return round($score, 1);
    }

    /**
     * Obtener historial detallado de asistencias
     */
    private function obtenerHistorialAsistencias(Enrollment $enrollment): array
    {
        return $enrollment->attendances()
            ->with(['classSession.module'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($attendance) {
                $classSession = $attendance->classSession;

                return [
                    'id' => $attendance->id,
                    'fecha' => $classSession->start_time?->format('Y-m-d'),
                    'hora_inicio' => $classSession->start_time?->format('H:i'),
                    'hora_fin' => $classSession->end_time?->format('H:i'),
                    'clase' => $classSession->title ?? 'Sin título',
                    'modulo' => $classSession->module->name ?? 'Sin módulo',
                    'estado' => $attendance->status->value,
                    'created_at' => $attendance->created_at?->toISOString(),
                ];
            })
            ->toArray();
    }

    /**
     * Obtener historial detallado de notas
     */
    private function obtenerHistorialNotas(Enrollment $enrollment): array
    {
        return $enrollment->grades()
            ->with(['exam'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($grade) {
                return [
                    'id' => $grade->id,
                    'examen' => $grade->exam->title ?? 'Sin título',
                    'nota' => (float) $grade->grade,
                    'feedback' => $grade->feedback,
                    'fecha' => $grade->created_at?->format('Y-m-d'),
                    'created_at' => $grade->created_at?->toISOString(),
                ];
            })
            ->toArray();
    }

    /**
     * Obtener progreso por módulos
     */
    private function obtenerProgresoModulos(Enrollment $enrollment): array
    {
        // Obtener todas las clases del grupo organizadas por módulo
        $classesByModule = ClassSession::where('group_id', $enrollment->group_id)
            ->with(['module', 'attendances' => function($query) use ($enrollment) {
                $query->where('enrollment_id', $enrollment->id);
            }])
            ->get()
            ->groupBy('module_id');

        $modulos = [];

        foreach ($classesByModule as $moduleId => $classes) {
            $module = $classes->first()->module;

            $totalClases = $classes->count();
            $clasesAsistidas = $classes->filter(function ($class) {
                return $class->attendances->whereIn('status', [
                    AttendanceStatus::Present,
                    AttendanceStatus::Late
                ])->count() > 0;
            })->count();

            // Obtener notas del módulo (si existen exámenes asociados)
            $gradesModulo = $enrollment->grades()
                ->whereHas('exam', function($query) use ($moduleId) {
                    $query->where('module_id', $moduleId);
                })
                ->get();

            $promedioNotas = $gradesModulo->isNotEmpty()
                ? round($gradesModulo->avg('grade'), 2)
                : null;

            $porcentajeAsistencia = $totalClases > 0
                ? round(($clasesAsistidas / $totalClases) * 100, 1)
                : 0;

            $modulos[] = [
                'modulo_id' => $moduleId,
                'modulo' => $module->name ?? 'Sin nombre',
                'clases_totales' => $totalClases,
                'clases_asistidas' => $clasesAsistidas,
                'porcentaje_asistencia' => $porcentajeAsistencia,
                'promedio_notas' => $promedioNotas,
                'completado' => $porcentajeAsistencia >= 80 && ($promedioNotas === null || $promedioNotas >= 60),
            ];
        }

        return $modulos;
    }

    /**
     * Obtener timeline de eventos del estudiante
     */
    private function obtenerTimeline(Enrollment $enrollment, $certificate = null): array
    {
        $timeline = [];

        // Evento: Registro del usuario
        if ($enrollment->user->created_at) {
            $timeline[] = [
                'fecha' => $enrollment->user->created_at->format('Y-m-d'),
                'tipo' => 'registro',
                'descripcion' => 'Alumno registrado en el sistema',
                'timestamp' => $enrollment->user->created_at->toISOString(),
            ];
        }

        // Evento: Matrícula en el curso
        if ($enrollment->created_at) {
            $courseName = $enrollment->group->courseVersion->course->name ?? 'un curso';
            $timeline[] = [
                'fecha' => $enrollment->created_at->format('Y-m-d'),
                'tipo' => 'matricula',
                'descripcion' => "Matriculado en {$courseName}",
                'timestamp' => $enrollment->created_at->toISOString(),
            ];
        }

        // Evento: Primera clase asistida
        $primeraAsistencia = $enrollment->attendances()
            ->where('status', AttendanceStatus::Present)
            ->orderBy('created_at', 'asc')
            ->first();

        if ($primeraAsistencia) {
            $timeline[] = [
                'fecha' => $primeraAsistencia->created_at->format('Y-m-d'),
                'tipo' => 'primera_clase',
                'descripcion' => 'Asistió a su primera clase',
                'timestamp' => $primeraAsistencia->created_at->toISOString(),
            ];
        }

        // Evento: Cambios de estado académico
        if ($enrollment->academic_status->value === 'completed') {
            $timeline[] = [
                'fecha' => $enrollment->updated_at->format('Y-m-d'),
                'tipo' => 'completado',
                'descripcion' => 'Completó el curso',
                'timestamp' => $enrollment->updated_at->toISOString(),
            ];
        }

        if ($enrollment->academic_status->value === 'dropped') {
            $timeline[] = [
                'fecha' => $enrollment->updated_at->format('Y-m-d'),
                'tipo' => 'desercion',
                'descripcion' => 'Abandonó el curso',
                'timestamp' => $enrollment->updated_at->toISOString(),
            ];
        }

        // Evento: Certificado emitido
        if ($certificate) {
            $timeline[] = [
                'fecha' => $certificate->created_at->format('Y-m-d'),
                'tipo' => 'certificado',
                'descripcion' => 'Certificado emitido',
                'timestamp' => $certificate->created_at->toISOString(),
            ];
        }

        // Ordenar por fecha descendente
        usort($timeline, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return $timeline;
    }
}
