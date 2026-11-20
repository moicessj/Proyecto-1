<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . "/conexion.php";

$reporte_id = $_GET['id'] ?? null;
if (!$reporte_id) {
    header("Location: pagina_principal.php");
    exit();
}

// Obtener el rol del usuario autenticado
$sqlUser = "SELECT rol
            FROM public.usuario
            WHERE id_usuario = :id_usuario
            LIMIT 1";

$stmtUser = $conexion->prepare($sqlUser);

$stmtUser->execute([
    ':id_usuario' => $_SESSION['id_usuario']
]);

$rolActual = $stmtUser->fetchColumn();

// Comprobar que sea administrador
if ($rolActual !== 'administrador') {
    http_response_code(403);
    exit("No tienes permiso para gestionar reportes.");
}

// Obtener el ID del administrador
$tecnico_id = $_SESSION['id_usuario'];
$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario de actualización de estado / asignación / observación
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nuevo_estado = trim($_POST['estado'] ?? '');
    $asignarse = isset($_POST['asignarse']);
    $comentario = trim($_POST['comentario'] ?? '');

    try {
        $conexion->beginTransaction();

        // 1. Asignar técnico si se marcó la casilla o si no tiene técnico y se envía
        if ($asignarse) {
            $sqlAsignar = "UPDATE public.reportes SET tecnico_id = :tecnico_id WHERE id = :id";
            $stmtAsignar = $conexion->prepare($sqlAsignar);
            $stmtAsignar->execute([':tecnico_id' => $tecnico_id, ':id' => $reporte_id]);
        }

        // 2. Actualizar estado y fecha de resolución si pasa a resuelto
        if (!empty($nuevo_estado)) {
            $fecha_res = ($nuevo_estado === 'Resuelto') ? date('Y-m-d H:i:s') : null;
            $sqlEstado = "UPDATE public.reportes SET estado = :estado, fecha_resolucion = :fecha_res WHERE id = :id";
            $stmtEstado = $conexion->prepare($sqlEstado);
            $stmtEstado->execute([':estado' => $nuevo_estado, ':fecha_res' => $fecha_res, ':id' => $reporte_id]);
        }

        // 3. Registrar observación si se escribió un comentario
        if (!empty($comentario)) {
            $sqlObs = "INSERT INTO public.observaciones (reporte_id, tecnico_id, comentario) VALUES (:reporte_id, :tecnico_id, :comentario)";
            $stmtObs = $conexion->prepare($sqlObs);
            $stmtObs->execute([
                ':reporte_id' => $reporte_id,
                ':tecnico_id' => $tecnico_id,
                ':comentario' => $comentario
            ]);
        }

        $conexion->commit();
        $mensaje = "¡Solicitud actualizada exitosamente!";
        $tipo_mensaje = "exito";
    } catch (Exception $e) {
        $conexion->rollBack();
        $mensaje = "Error al actualizar: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// Consultar detalles del reporte
$sqlDetalle = "SELECT r.*, u.\"nombre_Completo\" AS solicitante, u.departamento, t.\"nombre_Completo\" AS tecnico_nombre
               FROM public.reportes r
               INNER JOIN public.usuario u ON r.usuario_id = u.id_usuario
               LEFT JOIN public.usuario t ON r.tecnico_id = t.id_usuario
               WHERE r.id = :id LIMIT 1";
$stmtDetalle = $conexion->prepare($sqlDetalle);
$stmtDetalle->execute([':id' => $reporte_id]);
$reporte = $stmtDetalle->fetch(PDO::FETCH_ASSOC);

if (!$reporte) {
    header("Location: pagina_principal.php");
    exit();
}

// Consultar observaciones asociadas al reporte
$sqlObsList = "SELECT o.*, u.\"nombre_Completo\" AS tecnico 
               FROM public.observaciones o
               INNER JOIN public.usuario u ON o.tecnico_id = u.id_usuario
               WHERE o.reporte_id = :id
               ORDER BY o.fecha DESC";
$stmtObsList = $conexion->prepare($sqlObsList);
$stmtObsList->execute([':id' => $reporte_id]);
$observaciones = $stmtObsList->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Reporte #<?php echo htmlspecialchars($reporte['id']); ?></title>
    <link rel="stylesheet" href="estilos/pagina_principal.css">
</head>
<body>
    <header>
        <h1>Gestión de Solicitud</h1>
        <nav aria-label="Menú de gestión">
            <a href="pagina_principal.php">← Volver al Panel</a> | 
            <a href="cerrar_sesion.php">Cerrar Sesión</a>
        </nav>
    </header>

    <main>
        <?php if (!empty($mensaje)): ?>
            <div role="alert" style="padding: 0.8rem; margin-bottom: 1rem; border-left: 4px solid <?php echo ($tipo_mensaje === 'exito') ? '#2e7d32' : '#c62828'; ?>; font-weight: bold; color: <?php echo ($tipo_mensaje === 'exito') ? '#1b5e20' : '#b71c1c'; ?>;">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>

        <section style="margin-bottom: 2rem;">
            <h2>Reporte #<?php echo htmlspecialchars($reporte['id']); ?>: <?php echo htmlspecialchars($reporte['asunto']); ?></h2>
            <p><strong>Fecha de registro:</strong> <?php echo htmlspecialchars($reporte['fecha_creacion']); ?></p>
            <p><strong>Solicitante:</strong> <?php echo htmlspecialchars($reporte['solicitante']); ?> (Depto: <?php echo htmlspecialchars($reporte['departamento']); ?>)</p>
            <p><strong>Tipo de Problema:</strong> <?php echo htmlspecialchars($reporte['tipo_problema']); ?></p>
            <p><strong>Prioridad:</strong> <?php echo htmlspecialchars($reporte['prioridad']); ?></p>
            <p><strong>Estado Actual:</strong> <?php echo htmlspecialchars($reporte['estado']); ?></p>
            <p><strong>Técnico Asignado:</strong> <?php echo htmlspecialchars($reporte['tecnico_nombre'] ?? 'Ninguno'); ?></p>
            <div style="margin-top: 1rem;">
                <strong>Descripción detallada:</strong>
                <p style="background: #f9f9f9; padding: 1rem; margin-top: 0.3rem; border: 1px solid #ddd;"><?php echo nl2br(htmlspecialchars($reporte['descripcion'])); ?></p>
            </div>
        </section>

        <section style="background: #fdfdfd; border: 1px solid #ccc; padding: 1.5rem; margin-bottom: 2rem;">
            <h3>Actualizar Estado y Registrar Observación</h3>
            <form method="POST" action="detalle_reporte.php?id=<?php echo $reporte['id']; ?>">

                <?php if (empty($reporte['tecnico_id'])): ?>
                    <div style="margin-bottom: 1rem;">
                        <label>
                            <input type="checkbox" name="asignarse" value="1"> Asignarme este reporte como técnico responsable
                        </label>
                    </div>
                <?php endif; ?>

                <div style="margin-bottom: 1rem;">
                    <label for="estado" style="display: block; font-weight: bold; margin-bottom: 0.3rem;">Cambiar Estado:</label>
                    <select id="estado" name="estado" style="padding: 0.5rem; font-size: 1rem;">
                        <option value="Pendiente" <?php echo ($reporte['estado'] === 'Pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                        <option value="En proceso" <?php echo ($reporte['estado'] === 'En proceso') ? 'selected' : ''; ?>>En proceso</option>
                        <option value="Resuelto" <?php echo ($reporte['estado'] === 'Resuelto') ? 'selected' : ''; ?>>Resuelto</option>
                    </select>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="comentario" style="display: block; font-weight: bold; margin-bottom: 0.3rem;">Agregar Observación Técnica:</label>
                    <textarea id="comentario" name="comentario" rows="3" placeholder="Escribe detalles de la solución o avance..." style="width: 100%; padding: 0.5rem; font-size: 1rem;"></textarea>
                </div>

                <button type="submit" style="padding: 0.6rem 1.2rem; font-size: 1rem; cursor: pointer; background-color: #243b55; color: white; border: none; border-radius: 4px;">Guardar Cambios</button>
            </form>
        </section>

        <section>
            <h3>Historial de Observaciones</h3>
            <?php if (empty($observaciones)): ?>
                <p>No hay observaciones registradas para este reporte.</p>
            <?php else: ?>
                <ul style="list-style: none; padding: 0; margin-top: 1rem;">
                    <?php foreach ($observaciones as $obs): ?>
                        <li style="border-bottom: 1px solid #ddd; padding: 1rem 0;">
                            <small style="color: #666;"><?php echo htmlspecialchars($obs['fecha']); ?> - <strong><?php echo htmlspecialchars($obs['tecnico']); ?></strong>:</small>
                            <p style="margin-top: 0.3rem;"><?php echo nl2br(htmlspecialchars($obs['comentario'])); ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
