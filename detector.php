<?php
/**
 * detector.php — Punto de entrada JSON del sistema VAT.
 *
 * Recibe peticiones POST en JSON con { "action": "detect"|"humanize", "text": "..." }
 * y devuelve JSON. No hay HTML acá: esto es una API pura que app.js consume
 * por fetch(). Si este archivo devolviera HTML o texto plano en vez de
 * JSON, app.js no podría leer los datos con response.json() y el frontend
 * fallaría silenciosamente al parsear la respuesta.
 *
 * ARQUITECTURA:
 * - action=detect   → CLASIFICADOR REAL (modelo entrenado, calibrado).
 * - action=humanize → LLM generativo (Llama-3-8B-Instruct vía chat),
 *                      apropiado porque reescribir texto SÍ es una tarea
 *                      generativa legítima, a diferencia de "inventar" un
 *                      porcentaje de detección.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0'); // los errores de PHP no deben filtrarse como HTML dentro del JSON de respuesta

require_once __DIR__ . '/config.php';
$prompts = require __DIR__ . '/prompts.php';

/**
 * Corta la ejecución y devuelve un error JSON con el código HTTP indicado.
 * Centralizar esto evita que cada punto de fallo del script tenga que
 * repetir header + json_encode + exit por su cuenta, y garantiza que
 * TODOS los errores tengan el mismo formato ({ok:false, error:"..."}),
 * así app.js solo necesita revisar una sola estructura.
 */
function json_error($status, $message) {
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Devuelve una respuesta JSON exitosa, fusionando {ok:true} con los datos
 * propios de la acción. Sin esta función, cada acción tendría que acordarse
 * de agregar manualmente el campo "ok" — fácil de olvidar en alguna rama.
 */
function json_ok($data) {
    echo json_encode(array_merge(array('ok' => true), $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- Validación de la petición entrante ---------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Método no permitido. Esta API solo acepta POST con JSON.');
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    json_error(400, 'Cuerpo de la petición vacío. Se esperaba un JSON con "action" y "text".');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_error(400, 'JSON inválido. Revisá el formato que envía app.js.');
}

$action = isset($payload['action']) ? strtolower(trim((string) $payload['action'])) : '';
$text   = isset($payload['text']) ? trim((string) $payload['text']) : '';

if ($action !== 'detect' && $action !== 'humanize') {
    json_error(400, 'Acción desconocida. Usá "detect" o "humanize".');
}

if ($text === '') {
    json_error(400, 'El texto está vacío. Pegá contenido antes de continuar.');
}

// Truncar en vez de rechazar: mejor devolver un análisis parcial que un
// error molesto si el usuario pegó un texto un poco más largo de lo normal.
if (mb_strlen($text, 'UTF-8') > MAX_INPUT_CHARS) {
    $text = mb_substr($text, 0, MAX_INPUT_CHARS, 'UTF-8');
}

/* --- Cliente HTTP genérico hacia Hugging Face ---------------------------- */

/**
 * Llama a un endpoint del router de Hugging Face con reintentos automáticos
 * cuando el modelo está "despertando" (503 con estimated_time). Sirve tanto
 * para el clasificador como para el chat: ambos comparten la misma lógica
 * de red y manejo de errores, y solo cambian la URL y el cuerpo del POST,
 * que arma cada acción por separado más abajo.
 *
 * El parámetro $abortarSiFalla decide qué hacer cuando el modelo falla
 * DESPUÉS de agotar los reintentos:
 *   - true  (comportamiento de antes): corta todo con json_error.
 *   - false: devuelve null en vez de cortar, para que quien llamó a esta
 *            función pueda probar el SIGUIENTE modelo candidato en vez de
 *            romper toda la petición. Esto es lo que permite la cadena de
 *            respaldo de la acción "detect" (ver más abajo): si el primer
 *            modelo de la lista ya no está disponible, se prueba el
 *            siguiente automáticamente, sin que el usuario vea un error.
 *
 * El error 401 (token inválido) es la única excepción: siempre corta,
 * incluso con $abortarSiFalla=false, porque un token inválido no se
 * arregla probando otro modelo — es un problema de config.php, no del
 * modelo elegido.
 *
 * QUÉ PASARÍA SI ESTA FUNCIÓN NO REINTENTARA: el primer "modelo cargando"
 * (algo normal y frecuente en el tier gratuito de HF) rompería la demo
 * aunque el modelo esté listo 5-10 segundos después.
 */
function hf_post($url, $body, $abortarSiFalla = true) {
    $intento = 0;
    $ultimoError = 'Error desconocido al contactar Hugging Face.';

    while ($intento < HF_MAX_RETRIES) {
        $intento++;

        $ch = curl_init($url);
        if ($ch === false) {
            json_error(500, 'No se pudo inicializar cURL. Verificá que la extensión curl esté habilitada en PHP.');
        }

        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . HF_API_TOKEN,
                'Content-Type: application/json',
                'Accept: application/json',
            ),
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => HF_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 20,
        ));

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Fallo de red (sin respuesta del servidor): reintentamos, puede
        // ser un corte momentáneo de conexión.
        if ($errno !== 0 || $response === false) {
            $ultimoError = 'Fallo de red cURL (código ' . $errno . '). Verificá la conexión del servidor hacia Hugging Face.';
            continue;
        }

        $decodificado = json_decode($response, true);

        // Modelo "despertando": HF devuelve 503 o un JSON con estimated_time.
        // Esperamos ese tiempo estimado (con un tope razonable) y reintentamos.
        if ($http === 503 || (is_array($decodificado) && isset($decodificado['estimated_time']))) {
            $espera = 8;
            if (is_array($decodificado) && isset($decodificado['estimated_time'])) {
                $espera = min(20, max(3, (int) ceil((float) $decodificado['estimated_time'])));
            }
            sleep($espera);
            $ultimoError = 'El modelo se está inicializando en Hugging Face. Reintentando automáticamente...';
            continue;
        }

        if ($http === 401) {
            // Siempre corta, incluso probando varios candidatos: un token
            // inválido no se arregla cambiando de modelo.
            json_error(502, 'Hugging Face rechazó el token (401). Revisá que HF_API_TOKEN en config.php sea válido y no esté revocado.');
        }

        if ($http === 403) {
            if (!$abortarSiFalla) return null;
            json_error(502, 'Sin acceso al modelo (403). Si es Llama-3-8B-Instruct, aceptá su licencia en huggingface.co con la misma cuenta del token.');
        }

        if ($http === 404) {
            if (!$abortarSiFalla) return null;
            json_error(502, 'Modelo o endpoint no encontrado (404). Revisá el ID del modelo y la URL en config.php.');
        }

        if ($http < 200 || $http >= 300) {
            // Acá cae, entre otros, el error "Model not supported by provider
            // hf-inference" (típicamente HTTP 400) — un modelo que quedó
            // fuera del tier gratuito. Con $abortarSiFalla=false, esto es
            // justo la señal para pasar al siguiente candidato.
            if (!$abortarSiFalla) return null;
            $pista = is_array($decodificado) && isset($decodificado['error']) ? (string) $decodificado['error'] : ('HTTP ' . $http);
            $ultimoError = 'La API de inferencia devolvió un error: ' . $pista;
            continue;
        }

        if (!is_array($decodificado)) {
            $ultimoError = 'Respuesta no-JSON del servidor de Hugging Face.';
            continue;
        }

        return $decodificado;
    }

    if (!$abortarSiFalla) return null;
    json_error(502, $ultimoError);
}

/* --- ACCIÓN: detect (clasificador real, con cadena de candidatos) -------- */

/**
 * Arma el cuerpo del POST según el TIPO de candidato: un clasificador
 * dedicado usa el formato "pipeline" clásico ({"inputs": texto}), mientras
 * que un modelo zero-shot necesita además la lista de etiquetas candidatas
 * dentro de "parameters" — son formatos de petición distintos para tareas
 * distintas.
 *
 * QUÉ PASARÍA SI USÁRAMOS EL MISMO PAYLOAD PARA AMBOS: el modelo zero-shot
 * no recibiría "candidate_labels" y respondería con un error de parámetros
 * faltantes, o el clasificador dedicado recibiría un parámetro que no
 * entiende y lo ignoraría sin problema (pero es más prolijo no mandarlo).
 */
function construir_payload_candidato($candidato, $texto) {
    if ($candidato['tipo'] === 'zero-shot-classification') {
        return array(
            'inputs' => $texto,
            'parameters' => array(
                'candidate_labels' => array(
                    'texto generado por una inteligencia artificial',
                    'texto escrito por una persona',
                ),
            ),
        );
    }
    return array('inputs' => $texto, 'options' => array('wait_for_model' => true));
}

/**
 * La respuesta de un clasificador puede venir anidada [[{...},{...}]] o
 * plana [{...},{...}]. Esta función normaliza ambos casos antes de leer
 * las etiquetas, para no repetir esa comprobación en cada intérprete.
 */
function extraer_items_clasificacion($decodificado) {
    $anidado = isset($decodificado[0]) && is_array($decodificado[0]) && !isset($decodificado[0]['label']);
    return $anidado ? $decodificado[0] : $decodificado;
}

/**
 * Interpreta la respuesta de un clasificador binario dedicado (tipo
 * 'text-classification'). Busca las etiquetas conocidas de "es IA" y
 * "es humano" entre varios formatos posibles (distintos modelos nombran
 * sus clases distinto: "Fake"/"Real", "ChatGPT"/"Human", "LABEL_1"/"LABEL_0").
 *
 * Devuelve null si no reconoce ninguna etiqueta — esa es la señal para
 * que el bucle de más abajo pruebe el siguiente candidato en vez de
 * devolver un resultado inventado.
 */
function interpretar_text_classification($decodificado) {
    $items = extraer_items_clasificacion($decodificado);
    $prob_ia = null;
    $prob_humano = null;

    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['label']) || !isset($item['score'])) continue;
        $etiqueta = mb_strtolower((string) $item['label']);
        $puntaje  = (float) $item['score'];

        if (strpos($etiqueta, 'chatgpt') !== false || strpos($etiqueta, 'fake') !== false
            || strpos($etiqueta, 'generated') !== false || $etiqueta === 'label_1') {
            $prob_ia = $puntaje;
        } elseif (strpos($etiqueta, 'human') !== false || strpos($etiqueta, 'real') !== false
            || $etiqueta === 'label_0') {
            $prob_humano = $puntaje;
        }
    }

    if ($prob_ia === null && $prob_humano !== null) $prob_ia = 1 - $prob_humano;
    if ($prob_humano === null && $prob_ia !== null) $prob_humano = 1 - $prob_ia;
    if ($prob_ia === null && $prob_humano === null) return null;

    return array('prob_ia' => $prob_ia, 'prob_humano' => $prob_humano);
}

/**
 * Interpreta la respuesta de un modelo zero-shot, que llega en un formato
 * totalmente distinto: { "labels": [...], "scores": [...] }, ambos
 * arrays en el mismo orden y ya ordenados de mayor a menor score.
 *
 * array_combine junta las dos listas paralelas en un solo diccionario
 * etiqueta→puntaje, así se puede buscar cada etiqueta por nombre en vez
 * de andar recorriendo posiciones de array a mano.
 */
function interpretar_zero_shot($decodificado) {
    if (!isset($decodificado['labels']) || !isset($decodificado['scores'])
        || !is_array($decodificado['labels']) || !is_array($decodificado['scores'])) {
        return null;
    }

    $mapa = array_combine($decodificado['labels'], $decodificado['scores']);

    $prob_ia     = isset($mapa['texto generado por una inteligencia artificial']) ? (float) $mapa['texto generado por una inteligencia artificial'] : null;
    $prob_humano = isset($mapa['texto escrito por una persona']) ? (float) $mapa['texto escrito por una persona'] : null;

    if ($prob_ia === null && $prob_humano !== null) $prob_ia = 1 - $prob_humano;
    if ($prob_humano === null && $prob_ia !== null) $prob_humano = 1 - $prob_ia;
    if ($prob_ia === null && $prob_humano === null) return null;

    return array('prob_ia' => $prob_ia, 'prob_humano' => $prob_humano);
}

/**
 * Interpreta la respuesta de TU PROPIO modelo de 4 clases, una vez que
 * esté entrenado y agregado a HF_CLASSIFIER_CANDIDATOS con
 * tipo='text-classification-4clases'. A diferencia de los otros dos
 * intérpretes, acá no hay que inferir nada por descarte: las 4 etiquetas
 * (human/chatgpt/claude/gemini) ya vienen directamente del modelo.
 */
function interpretar_4clases($decodificado) {
    $items = extraer_items_clasificacion($decodificado);
    $puntajes = array('human' => 0, 'chatgpt' => 0, 'claude' => 0, 'gemini' => 0);
    $huboAlgo = false;

    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['label']) || !isset($item['score'])) continue;
        $etiqueta = mb_strtolower((string) $item['label']);
        if (isset($puntajes[$etiqueta])) {
            $puntajes[$etiqueta] = (float) $item['score'];
            $huboAlgo = true;
        }
    }

    return $huboAlgo ? $puntajes : null;
}

if ($action === 'detect') {
    $resultadoBinario = null;
    $candidatoUsado = null;

    // Recorre la lista de candidatos EN ORDEN. En cuanto uno responde algo
    // interpretable, se usa ese y se corta el bucle (break) — no hace
    // falta seguir probando los demás.
    //
    // QUÉ PASARÍA SI NO HUBIERA ESTE BUCLE (como en la versión anterior):
    // el día que el único modelo configurado deje de estar disponible en
    // el tier gratuito de Hugging Face (que es exactamente lo que te
    // acaba de pasar), la detección se cae por completo hasta que alguien
    // note el error y cambie el código a mano.
    foreach (HF_CLASSIFIER_CANDIDATOS as $candidato) {
        $url = 'https://router.huggingface.co/hf-inference/models/' . $candidato['id'];
        $payload = construir_payload_candidato($candidato, $text);

        // abortarSiFalla=false: si este candidato no responde bien, hf_post
        // devuelve null en vez de cortar toda la petición con un error.
        $decodificado = hf_post($url, $payload, false);
        if ($decodificado === null) {
            continue; // este modelo no está disponible ahora mismo, probamos el siguiente
        }

        if ($candidato['tipo'] === 'text-classification-4clases') {
            $puntajes4 = interpretar_4clases($decodificado);
            if ($puntajes4 === null) continue;

            $total = array_sum($puntajes4);
            if ($total <= 0) continue;

            foreach ($puntajes4 as $clase => $valor) {
                $puntajes4[$clase] = (int) round(($valor / $total) * 100);
            }

            json_ok(array(
                'action' => 'detect',
                'scores' => $puntajes4,
                'aviso'  => 'Resultado de tu propio modelo entrenado (4 clases reales).',
                'modeloPropio' => true,
                'modeloUsado' => $candidato['id'],
            ));
        }

        $interpretado = ($candidato['tipo'] === 'zero-shot-classification')
            ? interpretar_zero_shot($decodificado)
            : interpretar_text_classification($decodificado);

        if ($interpretado === null) {
            continue; // respondió, pero en un formato que no reconocemos: probamos el siguiente
        }

        $resultadoBinario = $interpretado;
        $candidatoUsado = $candidato['id'];
        break;
    }

    if ($resultadoBinario === null) {
        json_error(502, 'Ninguno de los modelos de detección configurados está disponible en este momento. Probá de nuevo en unos minutos, o revisá HF_CLASSIFIER_CANDIDATOS en config.php.');
    }

    $prob_humano = (int) round($resultadoBinario['prob_humano'] * 100);
    $prob_ia     = 100 - $prob_humano;

    $tercio = intdiv($prob_ia, 3);
    $resto  = $prob_ia - ($tercio * 3);

    json_ok(array(
        'action' => 'detect',
        'scores' => array(
            'human'   => $prob_humano,
            'chatgpt' => $tercio + $resto,
            'claude'  => $tercio,
            'gemini'  => $tercio,
        ),
        'aviso' => $prompts['aviso_deteccion'],
        'modeloPropio' => false,
        'modeloUsado' => $candidatoUsado, // útil para depurar: cuál candidato respondió
    ));
}

/* --- ACCIÓN: humanize (LLM generativo) ------------------------------------ */

// El prompt de sistema y la instrucción viven en prompts.php, no acá —
// así se pueden editar sin tocar la lógica de red de este archivo.
$decodificado = hf_post(HF_CHAT_URL, array(
    'model' => HF_CHAT_MODEL,
    'messages' => array(
        array('role' => 'system', 'content' => $prompts['humanizar_sistema']),
        array('role' => 'user', 'content' => $prompts['humanizar_instruccion_usuario'] . "\n\n" . $text),
    ),
    'temperature' => 0.85, // más alto que el default: buscamos variación natural, no la respuesta más "esperable"
    'max_tokens' => 1024,
));

$reescrito = '';
if (isset($decodificado['choices'][0]['message']['content'])) {
    $reescrito = (string) $decodificado['choices'][0]['message']['content'];
}

// Algunos modelos envuelven la respuesta en un bloque de código markdown
// (```texto```) aunque se les pida que no lo hagan. Lo sacamos por las dudas,
// porque si no, el texto "humanizado" le quedaría al usuario con comillas
// de código visibles, arruinando el propósito de la función.
$reescrito = preg_replace('/^\s*```(?:\w+)?\s*/u', '', $reescrito);
$reescrito = preg_replace('/\s*```\s*$/u', '', $reescrito);
$reescrito = trim($reescrito);

if ($reescrito === '') {
    json_error(502, 'La humanización devolvió texto vacío. Probá de nuevo o revisá el prompt en prompts.php.');
}

json_ok(array(
    'action' => 'humanize',
    'text'   => $reescrito,
));
