<?php
// php/audit_actions.php — Catálogo maestro de acciones de auditoría
//
// CÓMO AGREGAR UNA ACCIÓN NUEVA:
//   1. Elige el grupo correcto (o crea uno nuevo)
//   2. Agrega: 'clave_accion' => ['icono', 'color', 'Etiqueta legible']
//   3. En tu módulo PHP llama: registrarAudit($uid, 'clave_accion', 1);
//   Eso es todo — aparecerá automáticamente en filtros, tabla y CSV.
//
// COLORES disponibles: success | danger | info | warning | neutral | purple | orange
// ─────────────────────────────────────────────────────────────────────────────

function getAuditActions(): array {
    return [

        // ── SESIÓN ────────────────────────────────────────────────────────────
        'login_exitoso'   => ['🟢', 'success', 'Inicio de sesión'],
        'login_fallido'   => ['🔴', 'danger',  'Intento fallido de acceso'],
        'logout'          => ['🚪', 'neutral', 'Cierre de sesión'],
        'cambio_password' => ['🔑', 'info',    'Cambio de contraseña'],
        'reset_password'  => ['🔓', 'warning', 'Restablecimiento de contraseña'],

        // ── CONFIGURACIÓN ─────────────────────────────────────────────────────
        'config_empresa_guardada'        => ['🏢', 'info', 'Empresa actualizada'],
        'config_smtp_guardada'           => ['📧', 'info', 'Correo SMTP actualizado'],
        'config_notificaciones_guardada' => ['🔔', 'info', 'Notificaciones actualizadas'],
        'config_whatsapp_guardada'       => ['📱', 'info', 'WhatsApp actualizado'],
        'config_impresoras_guardada'     => ['🖨️', 'info', 'Impresoras actualizadas'],

        // ── USUARIOS & ROLES ──────────────────────────────────────────────────
        'usuario_creado'       => ['👤', 'success', 'Usuario creado'],
        'usuario_editado'      => ['✏️', 'info',    'Usuario editado'],
        'usuario_eliminado'    => ['🗑️', 'danger',  'Usuario eliminado'],
        'usuario_activado'     => ['✅', 'success', 'Usuario activado'],
        'usuario_desactivado'  => ['⛔', 'warning', 'Usuario desactivado'],
        'rol_asignado'         => ['🎭', 'info',    'Rol asignado'],

        // ── PERSONAS ──────────────────────────────────────────────────────────
        'persona_creada'    => ['👥', 'success', 'Persona registrada'],
        'persona_editada'   => ['✏️', 'info',    'Persona editada'],
        'persona_eliminada' => ['🗑️', 'danger',  'Persona eliminada'],

        // ── ESTUDIANTES ───────────────────────────────────────────────────────
        'cliente_creado'       => ['👤', 'success', 'Cliente registrado'],
        'cliente_editado'      => ['✏️', 'info',    'Cliente editado'],
        'cliente_eliminado'    => ['🗑️', 'danger',  'Cliente eliminado'],

        // ── EMPLEADOS ─────────────────────────────────────────────────────────
        'empleado_creado'    => ['💼', 'success', 'Empleado registrado'],
        'empleado_editado'   => ['✏️', 'info',    'Empleado editado'],
        'empleado_eliminado' => ['🗑️', 'danger',  'Empleado eliminado'],

        // ── TUTORES ───────────────────────────────────────────────────────────
        'tutor_creado'    => ['👨‍👧', 'success', 'Tutor registrado'],
        'tutor_editado'   => ['✏️', 'info',    'Tutor editado'],
        'tutor_eliminado' => ['🗑️', 'danger',  'Tutor eliminado'],

        // ── MATRÍCULA ─────────────────────────────────────────────────────────
        'contrato_creado'      => ['📋', 'success', 'Contrato registrado'],
        'contrato_editado'     => ['✏️', 'info',    'Contrato editado'],
        'contrato_anulado'     => ['❌', 'danger',  'Contrato anulado'],
        'periodo_creado'      => ['📅', 'success', 'Período escolar creado'],
        'periodo_editado'     => ['✏️', 'info',    'Período escolar editado'],
        'periodo_cerrado'     => ['🔒', 'warning', 'Período escolar cerrado'],

        // ── PLAN ACADÉMICO ────────────────────────────────────────────────────
        'asignatura_creada'    => ['📖', 'success', 'Asignatura creada'],
        'asignatura_editada'   => ['✏️', 'info',    'Asignatura editada'],
        'asignatura_eliminada' => ['🗑️', 'danger',  'Asignatura eliminada'],
        'plan_estudios_guardado' => ['📜', 'info',  'Plan de estudios guardado'],

        // ── CALIFICACIONES ────────────────────────────────────────────────────
        'prestamo_creado'           => ['💰', 'success', 'Préstamo registrado'],
        'prestamo_editado'          => ['✏️', 'info',    'Préstamo editado'],
        'periodo_eval_cerrado'      => ['🔒', 'warning', 'Período de evaluación cerrado'],

        // ── ASISTENCIA ────────────────────────────────────────────────────────
        'asistencia_registrada'  => ['✅', 'success', 'Asistencia registrada'],
        'asistencia_editada'     => ['✏️', 'info',    'Asistencia editada'],

        // ── DISCIPLINA ────────────────────────────────────────────────────────
        'incidencia_creada'    => ['⚠️', 'warning', 'Incidencia registrada'],
        'incidencia_editada'   => ['✏️', 'info',    'Incidencia editada'],
        'incidencia_eliminada' => ['🗑️', 'danger',  'Incidencia eliminada'],
        'medida_aplicada'      => ['📌', 'warning', 'Medida disciplinaria aplicada'],

        // ── PSICOLOGÍA ────────────────────────────────────────────────────────
        'expediente_creado'      => ['🧠', 'purple', 'Expediente psicológico creado'],
        'expediente_editado'     => ['✏️', 'info',   'Expediente editado'],
        'intervencion_registrada'=> ['💬', 'purple', 'Intervención registrada'],
        'cita_agendada'          => ['📞', 'info',   'Cita agendada'],
        'cita_cancelada'         => ['❌', 'danger', 'Cita cancelada'],

        // ── COBROS & PAGOS ────────────────────────────────────────────────────
        'pago_registrado'        => ['💳', 'success', 'Pago registrado'],
        'pago_anulado'           => ['❌', 'danger',  'Pago anulado'],
        'factura_generada'       => ['🧾', 'success', 'Factura generada'],
        'factura_anulada'        => ['❌', 'danger',  'Factura anulada'],
        'concepto_creado'        => ['🏷️', 'info',   'Concepto de cobro creado'],
        'concepto_editado'       => ['✏️', 'info',   'Concepto de cobro editado'],
        'concepto_eliminado'     => ['🗑️', 'danger', 'Concepto eliminado'],

        // ── HORARIOS ──────────────────────────────────────────────────────────
        'horario_creado'    => ['🕐', 'success', 'Horario creado'],
        'horario_editado'   => ['✏️', 'info',    'Horario editado'],
        'horario_eliminado' => ['🗑️', 'danger',  'Horario eliminado'],

        // ── INSTITUCIÓN ───────────────────────────────────────────────────────
        'empresa_creada'    => ['🏢', 'success', 'Empresa creada'],
        'empresa_editada'   => ['✏️', 'info',    'Empresa editada'],
        'sede_creada'      => ['📍', 'success', 'Sede creada'],
        'aula_creada'      => ['🚪', 'success', 'Aula creada'],
        'seccion_creada'   => ['🗃️', 'success', 'Sección creada'],

        // ── POLITÉCNICO ───────────────────────────────────────────────────────
        'carrera_creada'    => ['💰', 'success', 'Carrera creada'],
        'carrera_editada'   => ['✏️', 'info',    'Carrera editada'],
        'modulo_creado'     => ['📦', 'success', 'Módulo formativo creado'],
        'resultado_creado'  => ['🎯', 'success', 'Resultado de aprendizaje creado'],

        // ── REPORTES ──────────────────────────────────────────────────────────
        'reporte_generado'  => ['📈', 'orange', 'Reporte generado'],
        'reporte_exportado' => ['📥', 'orange', 'Reporte exportado'],

    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNCIÓN PÚBLICA: badge HTML para mostrar en la tabla
// ─────────────────────────────────────────────────────────────────────────────
function accionBadge(string $accion): string {
    $map = getAuditActions();

    if (!isset($map[$accion])) {
        // Acción desconocida: limpiar nombre automáticamente
        $label = ucfirst(str_replace(['_', '-'], ' ', $accion));
        return "<span class=\"badge badge-neutral\">⚙️ $label</span>";
    }

    [$icon, $color, $label] = $map[$accion];
    return "<span class=\"badge badge-{$color}\">{$icon} {$label}</span>";
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNCIÓN PÚBLICA: lista para el <select> de filtros
// Devuelve ['clave' => 'icono Etiqueta', ...] listo para hacer foreach
// ─────────────────────────────────────────────────────────────────────────────
function getAccionesParaFiltro(): array {
    $result = [];
    foreach (getAuditActions() as $key => [$icon, , $label]) {
        $result[$key] = "$icon $label";
    }
    return $result;
}
