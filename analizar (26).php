<?php
/* =============================================================================
 * VAT · Endpoint HTTP  POST /api/analizar.php
 * -----------------------------------------------------------------------------
 * Recibe {"texto": "..."} en JSON y devuelve el informe forense en JSON.
 * GET devuelve un chequeo de estado (para que el frontend sepa si hay PHP).
 * Si este archivo no existiera, el frontend recibiría 404 y usaría el motor
 * JavaScript local automáticamente (mismo resultado, ejecutado en el equipo).
 * ============================================================================= */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/MotorForense.php';

/* Cabeceras: JSON UTF-8 y medidas de seguridad. Sin "nosniff" algunos
 * navegadores podrían interpretar la respuesta como HTML (riesgo XSS); sin
 * no-store, proxies podrían cachear textos privados de los usuarios. */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/** Envía la respuesta y termina. Centralizarlo evita olvidar el código HTTP o el exit (que dejaría salida extra y JSON inválido). */
function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/* Límite de frecuencia por IP usando un archivo temporal (sin base de datos).
 * Sin este bloque, un bot podría lanzar análisis ilimitados y saturar la CPU. */
function limitarFrecuencia(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $archivo = sys_get_temp_dir() . '/vat_rl_' . hash('sha256', $ip);
    $ahora = time();
    $marcas = [];
    if (is_file($archivo)) {
        $marcas = array_filter(explode(',', (string) @file_get_contents($archivo)), static fn($t) => (int) $t > $ahora - 60);
    }
    if (count($marcas) >= VAT_LIMITE_POR_MINUTO) {
        responder(429, ['ok' => false, 'error' => 'Demasiadas solicitudes. Esperá un minuto e intentá de nuevo.']);
    }
    $marcas[] = (string) $ahora;
    @file_put_contents($archivo, implode(',', $marcas), LOCK_EX); // @: si /tmp no es escribible, se sigue sin límite en vez de romper el servicio.
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Chequeo de estado: permite al frontend detectar que PHP está disponible.
if ($metodo === 'GET') {
    responder(200, ['ok' => true, 'servicio' => 'VAT Forensic API', 'motor' => 'php', 'php' => PHP_VERSION]);
}
// Cualquier otro método distinto de POST se rechaza (evita usos no previstos).
if ($metodo !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido. Usá POST.']);
}

limitarFrecuencia();

/* Lectura del cuerpo con tope de tamaño: se leen como máximo ~4× el límite de
 * caracteres en bytes (UTF-8 usa hasta 4 bytes). Sin tope, un cuerpo enorme se
 * cargaría entero en memoria antes de validarlo. */
$crudo = (string) file_get_contents('php://input', false, null, 0, VAT_MAX_CARACTERES * 4 + 1024);
$entrada = json_decode($crudo, true);
if (!is_array($entrada) || !isset($entrada['texto']) || !is_string($entrada['texto'])) {
    responder(400, ['ok' => false, 'error' => 'Solicitud inválida: se esperaba JSON {"texto": "..."}']);
}
// Rechaza bytes que no son UTF-8 válido: preg_* con /u devolvería false y el análisis quedaría vacío.
if (!mb_check_encoding($entrada['texto'], 'UTF-8')) {
    responder(400, ['ok' => false, 'error' => 'El texto no está codificado en UTF-8.']);
}

try {
    $motor = new MotorForense(VAT_RUTA_MODELO);
    $resultado = $motor->analizar($entrada['texto']);
    responder($resultado['ok'] ? 200 : 422, $resultado);
} catch (Throwable $e) {
    // Se registra el detalle en el log del servidor pero no se expone al usuario (no filtrar rutas internas).
    error_log('[VAT] ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'Error interno del motor forense: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'consulte el registro del servidor.')]);
}
