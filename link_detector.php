<?php
/**
 * link_detector.php — Analiza una URL SIN abrirla.
 *
 * IDEA GENERAL: combina dos fuentes de información:
 *   (1) Heurísticas: reglas propias que miran el TEXTO del link.
 *   (2) VirusTotal: una base de datos externa donde decenas de antivirus
 *       ya opinaron sobre ese link.
 *
 * Tu servidor NUNCA visita el link, solo se lo pasa a VirusTotal.
 * Por eso un link malicioso no puede atacar tu servidor.
 */

/* ======================================================================
 * PASO 0: CONFIGURACIÓN INICIAL
 * ====================================================================== */

// Le avisa al navegador que la respuesta es JSON (no HTML).
// Si no estuviera, fetch().json() en enlaces.html fallaría.
header('Content-Type: application/json; charset=utf-8');

// Oculta los errores de PHP: si se mostraran, saldría HTML mezclado
// con el JSON y el frontend no podría leer la respuesta.
ini_set('display_errors', '0');

// Carga config.php, donde están tus claves (VT_API_KEY).
// La clave NUNCA se escribe en este archivo.
require_once __DIR__ . '/config.php';

/* ======================================================================
 * FUNCIONES AUXILIARES DE RESPUESTA
 * Hacen que TODAS las respuestas tengan el mismo formato: {ok: ...}
 * ====================================================================== */

// Corta el script y responde con un error.
// $status es el código HTTP (400 = petición mala, 405 = método no permitido).
function json_error($status, $message) {
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit; // Detiene la ejecución: nada de lo que sigue se corre.
}

// Responde con éxito. array_merge agrega {ok: true} a los datos que le pasemos.
function json_ok($data) {
    echo json_encode(array_merge(array('ok' => true), $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ======================================================================
 * PASO 1: VALIDAR LA PETICIÓN
 * ====================================================================== */

// Solo aceptamos POST (así es como envía los datos enlaces.html).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'Solo se acepta POST.');

// php://input es el "cuerpo" crudo de la petición. Lo decodificamos de
// JSON a un array de PHP (el segundo parámetro true pide array).
$payload = json_decode(file_get_contents('php://input'), true);

// Sacamos el campo "url" si existe; si no, queda vacío.
// trim() borra espacios al principio y al final.
$url = is_array($payload) && isset($payload['url']) ? trim((string) $payload['url']) : '';
if ($url === '') json_error(400, 'Pegá un link para analizar.');

// Si el usuario escribió "google.com" sin protocolo, le agregamos http://
// para que PHP pueda interpretarlo como URL.
if (!preg_match('#^https?://#i', $url)) $url = 'http://' . $url;

// Extraemos el dominio (host) de la URL. Ej: "https://a.com/x" → "a.com"
$host = parse_url($url, PHP_URL_HOST);

// Validamos: formato de URL correcto, que tenga dominio y que no
// sea absurdamente larga (más de 2048 caracteres).
if (!filter_var($url, FILTER_VALIDATE_URL) || !$host || mb_strlen($url) > 2048) {
    json_error(400, 'El link no tiene un formato válido.');
}

/* ======================================================================
 * PASO 2: HEURÍSTICAS LOCALES
 * Cada regla suma puntos de riesgo si detecta algo típico de estafas.
 * Es el "modelo propio" de tu proyecto: reglas explicables.
 * ====================================================================== */
function analizar_heuristicas($url, $host) {
    $puntos = 0;          // Puntaje de riesgo acumulado.
    $motivos = array();   // Lista de razones, para mostrarle al usuario.
    $u = strtolower($url); // Minúsculas, para buscar palabras sin importar mayúsculas.

    // Regla 1: el host son solo números (192.168.1.1) en vez de un nombre.
    // Los sitios legítimos casi siempre usan nombre de dominio.
    if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) { $puntos += 30; $motivos[] = 'Usa una dirección IP en vez de un dominio.'; }

    // Regla 2: el "@" en una URL hace que lo anterior se ignore como destino.
    // Ej: http://banco.com@sitio-malo.com lleva a sitio-malo.com.
    if (strpos($url, '@') !== false)                     { $puntos += 25; $motivos[] = 'Contiene "@" (puede ocultar el destino real).'; }

    // Regla 3: "xn--" indica caracteres especiales (punycode). Se usa para
    // imitar marcas con letras parecidas (ej: una "а" cirílica en vez de "a").
    if (strpos($host, 'xn--') !== false)                 { $puntos += 20; $motivos[] = 'Dominio con caracteres especiales (posible imitación de una marca).'; }

    // Regla 4: sin HTTPS no hay cifrado. Cuenta poco (mucho sitio legítimo
    // viejo tampoco lo usa), por eso suma solo 10.
    if (stripos($url, 'http://') === 0)                  { $puntos += 10; $motivos[] = 'No usa HTTPS.'; }

    // Regla 5: las URLs muy largas suelen esconder el destino real.
    if (strlen($url) > 75)                               { $puntos += 10; $motivos[] = 'URL muy larga.'; }

    // Regla 6: muchos subdominios (a.b.c.d.sitio.com) para confundir.
    if (substr_count($host, '.') > 3)                    { $puntos += 10; $motivos[] = 'Demasiados subdominios.'; }

    // Regla 7: muchos guiones (mi-banco-seguro-login.com).
    if (substr_count($host, '-') > 2)                    { $puntos += 10; $motivos[] = 'Muchos guiones en el dominio.'; }

    // Regla 8: terminaciones de dominio baratas o gratuitas, muy usadas
    // en estafas (no significa que todas lo sean).
    if (preg_match('/\.(zip|xyz|top|click|tk|gq|ml|cf)$/', $host)) { $puntos += 10; $motivos[] = 'Terminación de dominio frecuente en estafas.'; }

    // Regla 9: palabras típicas de phishing. Cuenta hasta 3 coincidencias
    // (7 puntos cada una) para que muchas palabras no disparen todo el puntaje.
    $palabras = array('login', 'verify', 'verificar', 'secure', 'account', 'cuenta', 'banco', 'premio', 'gratis', 'password', 'clave', 'billetera');
    $hallazgos = 0;
    foreach ($palabras as $p) {
        if (strpos($u, $p) !== false && $hallazgos < 3) { $hallazgos++; $puntos += 7; }
    }
    if ($hallazgos > 0) $motivos[] = 'Contiene palabras típicas de phishing (' . $hallazgos . ').';

    // min(100, ...) evita que el puntaje pase de 100.
    return array('puntos' => min(100, $puntos), 'motivos' => $motivos);
}

/* ======================================================================
 * PASO 3: VIRUSTOTAL
 * ====================================================================== */

// Función genérica para hablar con la API de VirusTotal.
// Devuelve un array: [código HTTP, datos ya decodificados].
function vt_request($metodo, $endpoint, $datos = null) {
    $ch = curl_init('https://www.virustotal.com/api/v3/' . $endpoint);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true, // Que devuelva la respuesta en vez de imprimirla.
        CURLOPT_HTTPHEADER     => array('x-apikey: ' . VT_API_KEY), // Tu clave, viaja en un encabezado.
        CURLOPT_TIMEOUT        => 25,   // Máximo 25 s esperando respuesta.
        CURLOPT_CONNECTTIMEOUT => 10,   // Máximo 10 s para conectar.
    );
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($datos); // Formato "url=..."
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Si falló la conexión (sin internet, sin curl): código 0.
    if ($resp === false) return array(0, null);
    return array($http, json_decode($resp, true));
}

// Consulta la reputación de la URL. Devuelve las estadísticas o null.
function consultar_virustotal($url) {
    // CORREGIDO: acá va el texto marcador, NO tu clave real.
    // Si no hay clave configurada, salteamos VirusTotal y seguimos
    // solo con las heurísticas.
    if (!defined('VT_API_KEY') || VT_API_KEY === '' || VT_API_KEY === 'TU_API_KEY_DE_VIRUSTOTAL') return null;

    // VirusTotal identifica cada URL por su versión en base64 "url-safe"
    // (reemplaza + y / por - y _, y quita los "=" del final).
    $id = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');

    // Primero preguntamos si ya la analizó alguna vez.
    list($http, $data) = vt_request('GET', 'urls/' . $id);

    // 404 = nunca fue analizada. La enviamos y esperamos el resultado.
    if ($http === 404) {
        list($h2, $d2) = vt_request('POST', 'urls', array('url' => $url));
        if ($h2 !== 200 || !isset($d2['data']['id'])) return null;
        $analisis = $d2['data']['id']; // ID del análisis en curso.

        // Preguntamos hasta 5 veces, con 3 s de pausa, si ya terminó.
        for ($i = 0; $i < 5; $i++) {
            sleep(3);
            list($h3, $d3) = vt_request('GET', 'analyses/' . $analisis);
            if ($h3 === 200 && isset($d3['data']['attributes']['status']) && $d3['data']['attributes']['status'] === 'completed') {
                return $d3['data']['attributes']['stats'];
            }
        }
        return null; // No terminó a tiempo.
    }

    // 200 = ya estaba analizada: devolvemos el último resumen.
    // Trae contadores: malicious, suspicious, harmless, undetected...
    if ($http === 200 && isset($data['data']['attributes']['last_analysis_stats'])) {
        return $data['data']['attributes']['last_analysis_stats'];
    }

    // 429 (demasiadas consultas), 401 (clave mala) u otro error:
    // devolvemos null y el sistema sigue solo con heurísticas.
    return null;
}

/* ======================================================================
 * PASO 4: EJECUTAR LOS DOS ANÁLISIS
 * ====================================================================== */
$heur = analizar_heuristicas($url, $host);
$vt   = consultar_virustotal($url); // Puede ser null.

/* ======================================================================
 * PASO 5: VEREDICTO COMBINADO
 * ====================================================================== */

// Si VirusTotal respondió, tomamos cuántos motores lo marcan; si no, 0.
$malicioso  = $vt ? (int) $vt['malicious']  : 0;
$sospechoso = $vt ? (int) $vt['suspicious'] : 0;

// Se evalúa de lo más grave a lo menos grave.
if ($malicioso >= 3 || $heur['puntos'] >= 60) {
    $veredicto = 'peligroso';    // 3+ motores lo marcan, o puntaje muy alto.
} elseif ($malicioso >= 1 || $sospechoso >= 1 || $heur['puntos'] >= 25) {
    $veredicto = 'sospechoso';   // Alguna señal de alerta.
} else {
    $veredicto = 'seguro';       // Sin señales (no garantiza que lo sea).
}

/* ======================================================================
 * PASO 6: RESPONDER AL FRONTEND
 * ====================================================================== */
json_ok(array(
    'url'        => $url,
    'veredicto'  => $veredicto,
    'heuristica' => $heur,
    'virustotal' => $vt, // null si no hay clave o VirusTotal no respondió.
));