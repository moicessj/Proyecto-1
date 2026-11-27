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

// Consultar quién es el propietario del reporte
$sqlPropietario = "SELECT usuario_id
                   FROM public.reportes
                   WHERE id = :id
                   LIMIT 1";

$stmtPropietario = $conexion->prepare($sqlPropietario);

$stmtPropietario->execute([
    ':id' => $reporte_id
]);

$idPropietario = $stmtPropietario->fetchColumn();

// Comprobar que el reporte exista
if ($idPropietario === false) {
    http_response_code(404);
    exit("El reporte no existe.");
}

// Comprobar los permisos del usuario
$esAdministrador = ($rolActual === 'administrador');

$esPropietario = (
    $rolActual === 'solicitante' &&
    (int)$idPropietario === (int)$_SESSION['id_usuario']
);

// Bloquear usuarios sin permisos
if (!$esAdministrador && !$esPropietario) {
    http_response_code(403);
    exit("No tienes permiso para consultar este reporte.");
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

        // Solo el administrador puede modificar reportes
    if (!$esAdministrador) {
        http_response_code(403);
        exit("No tienes permiso para modificar este reporte.");
    }

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
    <title>Detalle de Reporte #<?php echo htmlspecialchars($reporte['id']); ?></title>
    <link rel="stylesheet" href="pagina_principal.css/Estilos2.css">
</head>
<body>
    <header class="barra-superior">

    <div class="barra-contenido">

        <!-- LOGO Y NOMBRE DEL SISTEMA -->

        <a href="pagina_principal.php" class="marca-institucional">

            <img src="imagenes/logo.png"
                 alt="Logo del IVSS"
                 class="marca-logo">

            <div class="marca-texto">
                <strong>IVSS</strong>
                <span>Sistema de Mesa de Ayuda</span>
            </div>

        </a>

        <!-- NAVEGACIÓN -->

        <div class="acciones-nav">

            <a href="pagina_principal.php" class="cerrar-sesion">
                ← Volver al panel
            </a>

            <a href="cerrar_sesion.php" class="cerrar-sesion">
                Cerrar sesión
            </a>

        </div>

    </div>

</header>

    <main>
        <?php if (!empty($mensaje)): ?>
            <div role="alert" style="padding: 0.8rem; margin-bottom: 1rem; border-left: 4px solid <?php echo ($tipo_mensaje === 'exito') ? '#2e7d32' : '#c62828'; ?>; font-weight: bold; color: <?php echo ($tipo_mensaje === 'exito') ? '#1b5e20' : '#b71c1c'; ?>;">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endif; ?>

        <section class="detalle-reporte" aria-labelledby="titulo-detalle">

    <!-- ENCABEZADO DEL REPORTE -->

    <div class="detalle-reporte-encabezado">

        <div>
            <span class="detalle-etiqueta">
                DETALLE DE SOLICITUD
            </span>

            <h2 id="titulo-detalle">
                Reporte #<?php echo htmlspecialchars($reporte['id']); ?>:
                <?php echo htmlspecialchars($reporte['asunto']); ?>
            </h2>
        </div>

        <!-- ESTADO DEL REPORTE -->

        <span class="etiqueta-estado <?php
            echo ($reporte['estado'] === 'En proceso')
                ? 'estado-proceso'
                : (($reporte['estado'] === 'Resuelto')
                    ? 'estado-resuelto'
                    : 'estado-pendiente');
        ?>">
            <?php echo htmlspecialchars($reporte['estado']); ?>
        </span>

    </div>


    <!-- INFORMACIÓN GENERAL -->

    <div class="detalle-reporte-grid">

        <div class="detalle-dato">
            <span>Fecha de registro</span>
            <strong>
                <?php echo htmlspecialchars($reporte['fecha_creacion']); ?>
            </strong>
        </div>

        <div class="detalle-dato">
            <span>Solicitante</span>
            <strong>
                <?php echo htmlspecialchars($reporte['solicitante']); ?>
            </strong>
        </div>

        <div class="detalle-dato">
            <span>Departamento</span>
            <strong>
                <?php echo htmlspecialchars($reporte['departamento']); ?>
            </strong>
        </div>

        <div class="detalle-dato">
            <span>Tipo de problema</span>
            <strong>
                <?php echo htmlspecialchars($reporte['tipo_problema']); ?>
            </strong>
        </div>

        <div class="detalle-dato">
            <span>Prioridad</span>
            <strong>
                <?php echo htmlspecialchars($reporte['prioridad']); ?>
            </strong>
        </div>

        <div class="detalle-dato">
            <span>Técnico asignado</span>
            <strong>
                <?php echo htmlspecialchars($reporte['tecnico_nombre'] ?? 'Ninguno'); ?>
            </strong>
        </div>

    </div>


    <!-- DESCRIPCIÓN DEL PROBLEMA -->

    <div class="detalle-descripcion">

        <h3>Descripción detallada</h3>

        <p>
            <?php echo nl2br(htmlspecialchars($reporte['descripcion'])); ?>
        </p>

    </div>

</section>
        <?php if ($esAdministrador): ?>

<section class="panel-actualizacion">

    <!-- ENCABEZADO -->

    <div class="encabezado-actualizacion">

        <span class="detalle-etiqueta">
            ADMINISTRACIÓN
        </span>

        <h3>Gestionar reporte</h3>

        <p>
            Actualiza el estado de la solicitud y registra
            los avances de atención técnica.
        </p>

    </div>


    <!-- FORMULARIO -->

    <form method="POST"
          action="detalle_reporte.php?id=<?php echo (int)$reporte['id']; ?>"
          class="formulario-actualizacion">


        <!-- ASIGNACIÓN DE TÉCNICO -->

        <?php if (empty($reporte['tecnico_id'])): ?>

            <div class="asignacion-tecnico">

                <input type="checkbox"
                       id="asignarse"
                       name="asignarse"
                       value="1">

                <label for="asignarse">
                    Asignarme este reporte como técnico responsable
                </label>

            </div>

        <?php endif; ?>


        <!-- CAMBIAR ESTADO -->

        <div class="campo-actualizacion">

            <label for="estado">
                Estado de la solicitud
            </label>

            <select id="estado" name="estado">

                <option value="Pendiente"
                    <?php echo ($reporte['estado'] === 'Pendiente') ? 'selected' : ''; ?>>
                    Pendiente
                </option>

                <option value="En proceso"
                    <?php echo ($reporte['estado'] === 'En proceso') ? 'selected' : ''; ?>>
                    En proceso
                </option>

                <option value="Resuelto"
                    <?php echo ($reporte['estado'] === 'Resuelto') ? 'selected' : ''; ?>>
                    Resuelto
                </option>

            </select>

        </div>


        <!-- OBSERVACIÓN TÉCNICA -->

        <div class="campo-actualizacion">

            <label for="comentario">
                Observación técnica
            </label>

            <textarea
                id="comentario"
                name="comentario"
                rows="4"
                placeholder="Escribe los avances, detalles de la solución o acciones realizadas..."></textarea>

        </div>


        <!-- BOTÓN -->

        <button type="submit" class="boton-guardar-cambios">
            Guardar cambios
        </button>


    </form>

</section>

<?php else: ?>

    <section style="background: #fdfdfd; border: 1px solid #ccc; padding: 1.5rem; margin-bottom: 2rem;">

        <h3>Consulta de solicitud</h3>

        <p>
            Estás visualizando tu reporte.
            Solo el personal administrador puede modificar
            el estado o registrar observaciones.
        </p>

    </section>

<?php endif; ?>
        
        <!-- HISTORIAL DE OBSERVACIONES -->

<section class="panel-historial" aria-labelledby="titulo-historial">

    <!-- ENCABEZADO -->

    <div class="encabezado-historial">

        <span class="detalle-etiqueta">
            SEGUIMIENTO
        </span>

        <h3 id="titulo-historial">
            Historial de observaciones
        </h3>

        <p>
            Consulta los avances y las acciones realizadas
            durante la atención de esta solicitud.
        </p>

    </div>


    <!-- COMPROBAR SI EXISTEN OBSERVACIONES -->

    <?php if (empty($observaciones)): ?>

        <div class="historial-vacio">

            <p>
                Todavía no hay observaciones registradas
                para este reporte.
            </p>

        </div>

    <?php else: ?>


        <!-- LISTADO DE OBSERVACIONES -->

        <div class="lista-historial">

            <?php foreach ($observaciones as $obs): ?>

                <article class="item-historial">

                    <!-- INFORMACIÓN DEL TÉCNICO Y FECHA -->

                    <div class="meta-historial">

                        <strong>
                            <?php echo htmlspecialchars($obs['tecnico'], ENT_QUOTES, 'UTF-8'); ?>
                        </strong>

                        <time>
                            <?php echo htmlspecialchars($obs['fecha'], ENT_QUOTES, 'UTF-8'); ?>
                        </time>

                    </div>


                    <!-- CONTENIDO DE LA OBSERVACIÓN -->

                    <div class="contenido-observacion">

                        <p>
                            <?php echo nl2br(htmlspecialchars($obs['comentario'], ENT_QUOTES, 'UTF-8')); ?>
                        </p>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>
    </main>
</body>
</html>
