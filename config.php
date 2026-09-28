<?php
/**
 * config.php — Configuración central del detector/humanizador VAT.
 *
 * Hugging Face discontinuó el endpoint viejo (api-inference.huggingface.co)
 * en noviembre 2025. El endpoint actual es router.huggingface.co, con DOS
 * superficies distintas:
 *   - /v1/chat/completions            → formato OpenAI, para modelos de chat/instruct
 *   - /hf-inference/models/{MODEL_ID} → formato "pipeline" clásico, para clasificadores
 *
 * Por eso hay dos endpoints separados más abajo: uno para el clasificador
 * (detección) y otro para el LLM generativo (humanización). Si esta
 * distinción no existiera y todo apuntara a un solo endpoint, una de las
 * dos acciones (probablemente detección) fallaría con un error de formato,
 * porque un clasificador y un modelo de chat esperan cuerpos de petición
 * distintos.
 */

/**
 * Token de acceso a Hugging Face (Settings → Access Tokens → tipo "Read").
 * ⚠️ SEGURIDAD: nunca subas este archivo con un token real a un repositorio
 * público ni lo compartas en capturas de pantalla o chats. Si un token se
 * filtra, revocalo inmediatamente en huggingface.co/settings/tokens y
 * generá uno nuevo — cualquiera que lo vea puede gastar tu cuota a tu costa.
 */
define('HF_API_TOKEN', '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');

/* --- DETECCIÓN: clasificador real, calibrado ---------------------------- */

/**
 * Lista de candidatos, en orden de preferencia. detector.php prueba el
 * primero; si Hugging Face responde "modelo no soportado por el proveedor"
 * (algo que pasa cuando un modelo viejo queda fuera del tier gratuito),
 * prueba automáticamente el siguiente, sin que el usuario vea un error.
 *
 * Cada candidato tiene un 'tipo':
 *   - 'text-classification'    → modelo entrenado específicamente para
 *                                 detectar IA. Cuando funciona, es la
 *                                 señal más directa.
 *   - 'zero-shot-classification' → un modelo NLI general (no entrenado
 *                                 específicamente para esto) al que se le
 *                                 pregunta "¿esto es de una persona o de
 *                                 una IA?" con las dos opciones como texto.
 *                                 Es menos preciso que un clasificador
 *                                 dedicado, pero mucho más difícil que deje
 *                                 de estar disponible, porque es un modelo
 *                                 multilingüe usado por miles de proyectos.
 *                                 Sirve como red de seguridad final.
 *
 * QUÉ PASARÍA SI SOLO HUBIERA UN CANDIDATO (como antes): el día que ese
 * modelo puntual deje de estar disponible en el tier gratuito —como
 * pasó hoy—, la detección completa se cae hasta que alguien lo note y
 * cambie el código a mano.
 */
define('HF_CLASSIFIER_CANDIDATOS', array(
    array('id' => 'Hello-SimpleAI/chatgpt-detector-roberta', 'tipo' => 'text-classification'),
    array('id' => 'openai-community/roberta-base-openai-detector', 'tipo' => 'text-classification'),
    array('id' => 'MoritzLaurer/mDeBERTa-v3-base-mnli-xnli', 'tipo' => 'zero-shot-classification'),
));

/**
 * PRÓXIMO PASO (cuando el dataset propio de VAT esté completo y el modelo
 * de 4 clases entrenado en el notebook de Colab esté subido a Hugging Face):
 * agregá tu modelo AL PRINCIPIO de la lista de arriba, por ejemplo:
 *   array('id' => 'tu-usuario/vat-clasificador-es', 'tipo' => 'text-classification-4clases'),
 * Ese día, detector.php va a poder leer las 4 clases reales
 * (human/chatgpt/claude/gemini) directamente del modelo, en vez de
 * repartir el porcentaje de IA en partes iguales. El 'tipo' distinto
 * ('text-classification-4clases' en vez de 'text-classification') es lo
 * que le avisa a detector.php que ya no hay que repartir nada: las 4
 * etiquetas ya vienen calculadas por tu propio modelo.
 */

/* --- HUMANIZACIÓN: LLM generativo, uso legítimo -------------------------- */

/**
 * Llama-3-8B-Instruct vía la API tipo-OpenAI del router. Para reescribir
 * texto SÍ tiene sentido usar un LLM generativo: no le pedimos que
 * "califique" nada (como en el diseño original que fallaba), solo que
 * reescriba siguiendo las instrucciones de prompts.php.
 *
 * Es un modelo "gated" (con licencia): hay que aceptarla una vez en
 * huggingface.co/meta-llama/Meta-Llama-3-8B-Instruct con la misma cuenta
 * del token, o todas las llamadas de humanización van a devolver 403.
 */
define('HF_CHAT_MODEL', 'meta-llama/Meta-Llama-3-8B-Instruct');
define('HF_CHAT_URL', 'https://router.huggingface.co/v1/chat/completions');

/* --- Parámetros generales de red ----------------------------------------- */

/**
 * Tiempo máximo de espera de la respuesta del modelo (segundos). Sin este
 * límite, cURL esperaría indefinidamente si el modelo de HF tarda en
 * responder, y una sola petición trabada podría dejar el servidor esperando
 * para siempre en vez de devolver un error controlado.
 */
define('HF_TIMEOUT_SECONDS', 60);

/**
 * Reintentos cuando el modelo está "despertando" (503 / estimated_time).
 * Los modelos gratuitos de Hugging Face se "duermen" tras un tiempo sin
 * uso. Sin reintentos, el primer 503 del día rompería la demo aunque el
 * modelo esté listo pocos segundos después.
 */
define('HF_MAX_RETRIES', 3);

/**
 * Longitud máxima de texto aceptada (caracteres). Sin este límite, un
 * texto extremadamente largo podría superar el límite de tokens del
 * modelo (que sí rompe la petición con un error confuso) o hacer que la
 * factura de uso de la API crezca sin control.
 */
define('MAX_INPUT_CHARS', 8000);
