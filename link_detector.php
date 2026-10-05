<?php
/**
 * link_detector.php — Analiza una URL SIN abrirla.
 * Combina: (1) heurísticas locales sobre el texto de la URL y
 *          (2) reputación en VirusTotal (si hay API key).
 * El servidor nunca visita el link: solo se lo pasa a VirusTotal.
 * Eso evita que un link malicioso ataque tu servidor.
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

function json_error($status, $message) {
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok($data) {
    echo json_encode(array_merge(array('ok' => true), $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'Solo se acepta POST.');

$payload = json_decode(file_get_contents('php://input'), true);
$url = is_array($payload) && isset($payload['url']) ? trim((string) $payload['url']) : '';
if ($url === '') json_error(400, 'Pegá un link para analizar.');
if (!preg_match('#^https?://#i', $url)) $url = 'http://' . $url;

$host = parse_url($url, PHP_URL_HOST);
if (!filter_var($url, FILTER_VALIDATE_URL) || !$host || mb_strlen($url) > 2048) {
    json_error(400, 'El link no tiene un formato válido.');
}

/* --- 1) Heurísticas locales ------------------------------------------- */
function analizar_heuristicas($url, $host) {
    $puntos = 0;
    $motivos = array();
    $u = strtolower($url);

    if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) { $puntos += 30; $motivos[] = 'Usa una dirección IP en vez de un dominio.'; }
    if (strpos($url, '@') !== false)                     { $puntos += 25; $motivos[] = 'Contiene "@" (puede ocultar el destino real).'; }
    if (strpos($host, 'xn--') !== false)                 { $puntos += 20; $motivos[] = 'Dominio con caracteres especiales (posible imitación de una marca).'; }
    if (stripos($url, 'http://') === 0)                  { $puntos += 10; $motivos[] = 'No usa HTTPS.'; }
    if (strlen($url) > 75)                               { $puntos += 10; $motivos[] = 'URL muy larga.'; }
    if (substr_count($host, '.') > 3)                    { $puntos += 10; $motivos[] = 'Demasiados subdominios.'; }
    if (substr_count($host, '-') > 2)                    { $puntos += 10; $motivos[] = 'Muchos guiones en el dominio.'; }
    if (preg_match('/\.(zip|xyz|top|click|tk|gq|ml|cf)$/', $host)) { $puntos += 10; $motivos[] = 'Terminación de dominio frecuente en estafas.'; }

    $palabras = array('login', 'verify', 'verificar', 'secure', 'account', 'cuenta', 'banco', 'premio', 'gratis', 'password', 'clave', 'billetera');
    $hallazgos = 0;
    foreach ($palabras as $p) {
        if (strpos($u, $p) !== false && $hallazgos < 3) { $hallazgos++; $puntos += 7; }
    }
    if ($hallazgos > 0) $motivos[] = 'Contiene palabras típicas de phishing (' . $hallazgos . ').';

    return array('puntos' => min(100, $puntos), 'motivos' => $motivos);
}

/* --- 2) VirusTotal ------------------------------------------------------ */
function vt_request($metodo, $endpoint, $datos = null) {
    $ch = curl_init('https://www.virustotal.com/api/v3/' . $endpoint);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array('x-apikey: ' . VT_API_KEY),
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    );
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($datos);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return array(0, null);
    return array($http, json_decode($resp, true));
}

function consultar_virustotal($url) {
    if (!defined('VT_API_KEY') || VT_API_KEY === '' || VT_API_KEY === '588f702d90469e4b649c32d602d482c15002ef49ac96cb36411d2b98bd776f97') return null;

    $id = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    list($http, $data) = vt_request('GET', 'urls/' . $id);

    // Nunca fue analizada: la enviamos y esperamos el resultado.
    if ($http === 404) {
        list($h2, $d2) = vt_request('POST', 'urls', array('url' => $url));
        if ($h2 !== 200 || !isset($d2['data']['id'])) return null;
        $analisis = $d2['data']['id'];
        for ($i = 0; $i < 5; $i++) {
            sleep(3);
            list($h3, $d3) = vt_request('GET', 'analyses/' . $analisis);
            if ($h3 === 200 && isset($d3['data']['attributes']['status']) && $d3['data']['attributes']['status'] === 'completed') {
                return $d3['data']['attributes']['stats'];
            }
        }
        return null;
    }

    if ($http === 200 && isset($data['data']['attributes']['last_analysis_stats'])) {
        return $data['data']['attributes']['last_analysis_stats'];
    }
    return null; // 429 (límite) u otro error: seguimos solo con heurísticas
}

$heur = analizar_heuristicas($url, $host);
$vt   = consultar_virustotal($url);

/* --- 3) Veredicto combinado --------------------------------------------- */
$malicioso  = $vt ? (int) $vt['malicious']  : 0;
$sospechoso = $vt ? (int) $vt['suspicious'] : 0;

if ($malicioso >= 3 || $heur['puntos'] >= 60) {
    $veredicto = 'peligroso';
} elseif ($malicioso >= 1 || $sospechoso >= 1 || $heur['puntos'] >= 25) {
    $veredicto = 'sospechoso';
} else {
    $veredicto = 'seguro';
}

json_ok(array(
    'url'        => $url,
    'veredicto'  => $veredicto,
    'heuristica' => $heur,
    'virustotal' => $vt, // null si no hay API key o no respondió
));