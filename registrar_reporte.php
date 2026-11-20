<?php
session_start();

// Verificar que el usuario haya iniciado sesión
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

// Verificar que la petición venga del formulario por método POST
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_reporte"])) {

    // Incluir la conexión a la base de datos 'proyecto'
    require_once __DIR__ . "/conexion.php";

    // Recoger y limpiar los datos del formulario
    $departamento  = trim($_POST["departamento"] ?? '');
    $tipo_problema = trim($_POST["tipo_problema"] ?? '');
    $asunto        = trim($_POST["asunto"] ?? '');
    $descripcion   = trim($_POST["descripcion"] ?? '');
    $prioridad     = trim($_POST["prioridad"] ?? 'Media');

    // Validar que los campos obligatorios no estén vacíos
    if (empty($departamento) || empty($tipo_problema) || empty($asunto) || empty($descripcion)) {
        $_SESSION['mensaje_reporte'] = "Por favor, completa todos los campos obligatorios.";
        $_SESSION['tipo_mensaje'] = "error";
        header("Location: pagina_principal.php");
        exit();
    }

    try {
        // 1. Obtener el id_usuario (y actualizar su departamento si es necesario) a partir de la sesión
        // Nota: Ajusta la columna del WHERE si $_SESSION['usuario'] guarda el correo o el nombre
        // Verificar el rol del usuario en PostgreSQL
$sqlRol = "SELECT rol
           FROM public.usuario
           WHERE id_usuario = :id_usuario
           LIMIT 1";

$stmtRol = $conexion->prepare($sqlRol);

$stmtRol->execute([
    ':id_usuario' => $_SESSION['id_usuario']
]);

$rolActual = $stmtRol->fetchColumn();

// Permitir solamente a los solicitantes registrar reportes
if ($rolActual !== 'solicitante') {
    http_response_code(403);
    exit("No tienes permiso para registrar reportes.");
}

// Obtener el ID del solicitante autenticado
$usuario_id = $_SESSION['id_usuario'];

        // Opcional: Actualizar el departamento en el perfil del usuario si lo ingresó
        $sqlUpdateDepto = "UPDATE public.usuario SET departamento = :depto WHERE id_usuario = :id";
        $stmtDepto = $conexion->prepare($sqlUpdateDepto);
        $stmtDepto->execute([':depto' => $departamento, ':id' => $usuario_id]);

        // 2. Insertar el nuevo reporte en la tabla public.reportes
        $sqlInsert = "INSERT INTO public.reportes (usuario_id, tipo_problema, asunto, descripcion, prioridad) 
                      VALUES (:usuario_id, :tipo_problema, :asunto, :descripcion, :prioridad)";

        $stmtInsert = $conexion->prepare($sqlInsert);
        $stmtInsert->execute([
            ':usuario_id'    => $usuario_id,
            ':tipo_problema' => $tipo_problema,
            ':asunto'        => $asunto,
            ':descripcion'   => $descripcion,
            ':prioridad'     => $prioridad
        ]);

        // Guardar mensaje de éxito temporal en sesión (patrón PRG)
        $_SESSION['mensaje_reporte'] = "¡Reporte registrado exitosamente!";
        $_SESSION['tipo_mensaje'] = "exito";

    } catch (Exception $e) {
        $_SESSION['mensaje_reporte'] = "Error al guardar el reporte: " . $e->getMessage();
        $_SESSION['tipo_mensaje'] = "error";
    }

    // Redirigir de vuelta a la página principal
    header("Location: pagina_principal.php");
    exit();
} else {
    // Si intentan entrar directamente por URL sin enviar el formulario
    header("Location: pagina_principal.php");
    exit();
}