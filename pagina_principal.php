<?php
session_start();

// Comprueba si el usuario ha iniciado sesión; si no, lo redirige al index (login)
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . "/conexion.php";

$usuario_id = null;
$rol_usuario = null;
$lista_reportes = [];
$error_consulta = '';

try {
    // 1. Obtener id_usuario y rol del usuario autenticado
    $sqlUser = "SELECT id_usuario, rol
            FROM public.usuario
            WHERE id_usuario = :id_usuario
            LIMIT 1";

$stmtUser = $conexion->prepare($sqlUser);

$stmtUser->execute([
    ':id_usuario' => $_SESSION['id_usuario']
]);

$datosUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($datosUser) {
       $usuario_id = $datosUser['id_usuario'];
$rol_usuario = $datosUser['rol'];

if (!in_array($rol_usuario, ['solicitante', 'administrador'], true)) {
        http_response_code(403);
        exit("No tienes un rol autorizado para acceder al sistema.");
    }

    // Guardar el rol en la sesión correctamente
    $_SESSION['rol'] = $rol_usuario;

    // A partir de aquí continúa el código normal para listar reportes y estadísticas...



$_SESSION['rol'] = $rol_usuario;

        // Capturar filtros de búsqueda si existen
        $busqueda = trim($_GET['buscar'] ?? '');
        $filtro_estado = trim($_GET['estado'] ?? '');

        // Construir condiciones dinámicas de forma segura
        $condiciones = [];
        $parametros = [];

        // Si es solicitante, restringir solo a sus reportes
        if ($rol_usuario === 'solicitante') {
    $condiciones[] = "r.usuario_id = :usuario_id";
    $parametros[':usuario_id'] = $usuario_id;
}

        // Filtro por estado
        if (!empty($filtro_estado)) {
            $condiciones[] = "r.estado = :estado";
            $parametros[':estado'] = $filtro_estado;
        }

        // Búsqueda por asunto, departamento o tipo de problema
        if (!empty($busqueda)) {
            $condiciones[] = "(r.asunto ILIKE :buscar OR u.departamento ILIKE :buscar OR r.tipo_problema ILIKE :buscar)";
            $parametros[':buscar'] = '%' . $busqueda . '%';
        }

        $whereSQL = "";
        if (!empty($condiciones)) {
            $whereSQL = "WHERE " . implode(" AND ", $condiciones);
        }

        $sqlRep = "SELECT r.id, r.fecha_creacion, u.departamento, u.\"nombre_Completo\" AS solicitante, 
                          r.tipo_problema, r.asunto, r.prioridad, r.estado, t.\"nombre_Completo\" AS tecnico
                   FROM public.reportes r
                   INNER JOIN public.usuario u ON r.usuario_id = u.id_usuario
                   LEFT JOIN public.usuario t ON r.tecnico_id = t.id_usuario
                   $whereSQL
                   ORDER BY r.fecha_creacion DESC";

        $stmtRep = $conexion->prepare($sqlRep);
        $stmtRep->execute($parametros);
        $lista_reportes = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

        // 3. Si es administrador o técnico, calcular las estadísticas del panel de control
        $stats = [
            'total' => 0,
            'pendientes' => 0,
            'en_proceso' => 0,
            'resueltos' => 0
        ];

        if ($rol_usuario === 'administrador') {
            $sqlStats = "SELECT 
                            COUNT(*) AS total,
                            COUNT(*) FILTER (WHERE estado = 'Pendiente') AS pendientes,
                            COUNT(*) FILTER (WHERE estado = 'En proceso') AS en_proceso,
                            COUNT(*) FILTER (WHERE estado = 'Resuelto') AS resueltos
                         FROM public.reportes";
            $stmtStats = $conexion->prepare($sqlStats);
            $stmtStats->execute();
            $resultadoStats = $stmtStats->fetch(PDO::FETCH_ASSOC);

            if ($resultadoStats) {
                $stats = $resultadoStats;
            }
        }
    }
} catch (Exception $e) {
    $error_consulta = "No se pudieron cargar los reportes: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagina principal</title>
    <link rel="stylesheet" href="estilos/Estilos2.css">
    <link rel="icon" type="image/png" href="imagenes/logo.png">
</head>
<body>
    <header>
        <h1>Sistema de Mesa de Ayuda</h1>
        <nav aria-label="Menú principal">
    <ul>
        <li><a href="pagina_principal.php">Inicio</a></li>
        <?php if ($rol_usuario === 'solicitante'): ?>
            <li><a href="#">Mis Reportes</a></li>
        <?php endif; ?>
        <li><a href="cerrar_sesion.php">Cerrar Sesión</a></li>
    </ul>
</nav>
    </header>
    <main>
        <section>
            <!-- Esto mostrará dinámicamente el nombre de la persona que haya iniciado sesión -->
            <h2>Bienvenido, <?= htmlspecialchars($_SESSION['usuario'] ?? 'Usuario', ENT_QUOTES, 'UTF-8') ?></h2>
<?php if ($rol_usuario === 'administrador'): ?>
        <section style="margin-top: 2rem; margin-bottom: 2rem;">
            <h3>Panel de Control (Resumen de Reportes)</h3>
            <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 1rem;">
                <div style="border: 1px solid #ccc; padding: 1rem 1.5rem; min-width: 10rem;">
                    <span style="display: block; font-size: 0.9rem; color: #555;">Total de Reportes</span>
                    <strong style="font-size: 1.8rem; color: #243b55;"><?php echo (int)$stats['total']; ?></strong>
                </div>

                <div style="border: 1px solid #ccc; padding: 1rem 1.5rem; min-width: 10rem;">
                    <span style="display: block; font-size: 0.9rem; color: #555;">Pendientes</span>
                    <strong style="font-size: 1.8rem; color: #b71c1c;"><?php echo (int)$stats['pendientes']; ?></strong>
                </div>

                <div style="border: 1px solid #ccc; padding: 1rem 1.5rem; min-width: 10rem;">
                    <span style="display: block; font-size: 0.9rem; color: #555;">En Proceso</span>
                    <strong style="font-size: 1.8rem; color: #e65100;"><?php echo (int)$stats['en_proceso']; ?></strong>
                </div>

                <div style="border: 1px solid #ccc; padding: 1rem 1.5rem; min-width: 10rem;">
                    <span style="display: block; font-size: 0.9rem; color: #555;">Resueltos</span>
                    <strong style="font-size: 1.8rem; color: #1b5e20;"><?php echo (int)$stats['resueltos']; ?></strong>
                </div>
            </div>
        </section>
    <?php endif; ?>
            <p>
                Has iniciado sesión correctamente en el Sistema de Mesa de Ayuda.
            </p>
        </section>



   <?php if ($rol_usuario === 'solicitante'): ?>
    <section>
        <h3>Registrar Nuevo Reporte</h3>
        <?php if (isset($_SESSION['mensaje_reporte'])): ?>
            <div role="alert" style="padding: 0.8rem; margin-bottom: 1rem; border-left: 4px solid <?php echo ($_SESSION['tipo_mensaje'] === 'exito') ? '#2e7d32' : '#c62828'; ?>; font-weight: bold; color: <?php echo ($_SESSION['tipo_mensaje'] === 'exito') ? '#1b5e20' : '#b71c1c'; ?>;">
                <?php 
                    echo htmlspecialchars($_SESSION['mensaje_reporte']); 
                    unset($_SESSION['mensaje_reporte'], $_SESSION['tipo_mensaje']);
                ?>
            </div>
        <?php endif; ?>
        <form action="registrar_reporte.php" method="POST">

            <div style="margin-bottom: 1rem;">
                <label for="departamento" style="display: block; margin-bottom: 0.3rem; font-weight: bold;">Departamento:</label>
                <input type="text" id="departamento" name="departamento" required style="width: 100%; padding: 0.5rem; font-size: 1rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="tipo_problema" style="display: block; margin-bottom: 0.3rem; font-weight: bold;">Tipo de Problema:</label>
                <select id="tipo_problema" name="tipo_problema" required style="width: 100%; padding: 0.5rem; font-size: 1rem;">
                    <option value="" disabled selected>Seleccione una opción</option>
                    <option value="Computadora">Computadora</option>
                    <option value="Impresora">Impresora</option>
                    <option value="Internet">Internet</option>
                    <option value="Software">Software</option>
                    <option value="Otros">Otros</option>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="asunto" style="display: block; margin-bottom: 0.3rem; font-weight: bold;">Asunto:</label>
                <input type="text" id="asunto" name="asunto" required style="width: 100%; padding: 0.5rem; font-size: 1rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="descripcion" style="display: block; margin-bottom: 0.3rem; font-weight: bold;">Descripción detallada:</label>
                <textarea id="descripcion" name="descripcion" rows="4" required style="width: 100%; padding: 0.5rem; font-size: 1rem;"></textarea>
            </div>

            <div style="margin-bottom: 1rem;">
                <label for="prioridad" style="display: block; margin-bottom: 0.3rem; font-weight: bold;">Prioridad:</label>
                <select id="prioridad" name="prioridad" required style="width: 100%; padding: 0.5rem; font-size: 1rem;">
                    <option value="Baja">Baja</option>
                    <option value="Media" selected>Media</option>
                    <option value="Alta">Alta</option>
                </select>
            </div>

            <button type="submit" name="guardar_reporte" style="padding: 0.6rem 1.2rem; font-size: 1rem; cursor: pointer; background-color: #243b55; color: white; border: none; border-radius: 4px;">Enviar Reporte</button>
        </form>
    </section>
    <?php endif; ?>



    <section style="margin-top: 3rem;">
    <h3>Listado de Reportes</h3>

<form method="GET" action="pagina_principal.php" style="margin: 1rem 0; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
    <div>
        <label for="buscar" style="display: block; font-weight: bold; margin-bottom: 0.3rem;">Buscar:</label>
        <input type="text" id="buscar" name="buscar" placeholder="Asunto, departamento..." value="<?php echo htmlspecialchars($_GET['buscar'] ?? ''); ?>" style="padding: 0.5rem; font-size: 1rem;">
    </div>

    <div>
        <label for="estado" style="display: block; font-weight: bold; margin-bottom: 0.3rem;">Estado:</label>
        <select id="estado" name="estado" style="padding: 0.5rem; font-size: 1rem;">
            <option value="">Todos</option>
            <option value="Pendiente" <?php echo (($_GET['estado'] ?? '') === 'Pendiente') ? 'selected' : ''; ?>>Pendiente</option>
            <option value="En proceso" <?php echo (($_GET['estado'] ?? '') === 'En proceso') ? 'selected' : ''; ?>>En proceso</option>
            <option value="Resuelto" <?php echo (($_GET['estado'] ?? '') === 'Resuelto') ? 'selected' : ''; ?>>Resuelto</option>
        </select>
    </div>

    <div>
        <button type="submit" style="padding: 0.55rem 1rem; font-size: 1rem; cursor: pointer; background-color: #243b55; color: white; border: none; border-radius: 4px;">Filtrar</button>
        <a href="pagina_principal.php" style="margin-left: 0.5rem; font-size: 0.95rem; color: #243b55;">Limpiar</a>
    </div>
</form>

    <?php if (!empty($error_consulta)): ?>
        <p style="color: #b71c1c; font-weight: bold;"><?php echo htmlspecialchars($error_consulta); ?></p>
    <?php elseif (empty($lista_reportes)): ?>
        <p>No hay reportes registrados en este momento.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.95rem;">
                <thead>
                    <tr style="background-color: #f4f4f4; text-align: left; border-bottom: 2px solid #ccc;">
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Código</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Fecha</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Departamento</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Solicitante</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Problema</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Asunto</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Prioridad</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Estado</th>
                        <th style="padding: 0.75rem; border: 1px solid #ddd;">Técnico</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_reportes as $rep): ?>
                        <tr style="border-bottom: 1px solid #ddd;">
                           <td style="padding: 0.75rem; border: 1px solid #ddd;">
    <?php if ($rol_usuario === 'administrador'): ?>
        <a href="detalle_reporte.php?id=<?php echo $rep['id']; ?>" style="color: #243b55; font-weight: bold; text-decoration: underline;">
            #<?php echo htmlspecialchars($rep['id']); ?>
        </a>
    <?php else: ?>
        <span style="font-weight: bold; color: #555;">
            #<?php echo htmlspecialchars($rep['id']); ?>
        </span>
    <?php endif; ?>
</td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['fecha_creacion']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['departamento']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['solicitante']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['tipo_problema']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['asunto']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['prioridad']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['estado']); ?></td>
                            <td style="padding: 0.75rem; border: 1px solid #ddd;"><?php echo htmlspecialchars($rep['tecnico'] ?? 'Sin asignar'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

    </main>
</body>
</html>