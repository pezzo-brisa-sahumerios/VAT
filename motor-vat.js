/* =============================================================================
 * VAT · Motor Forense de Detección de Texto IA (JavaScript ES6, 100% local)
 * -----------------------------------------------------------------------------
 * Este archivo es la réplica exacta, en el navegador, del motor PHP
 * (src/*.php). Se usa en dos situaciones:
 *   1) Cuando la app se abre sin servidor PHP (por ejemplo, el index.html
 *      descomprimido directamente en un celular) → análisis totalmente offline.
 *   2) Como respaldo automático si la llamada a api/analizar.php falla.
 *
 * Cómo decide (resumen del protocolo forense):
 *   a) Normaliza el texto (Unicode NFC, saltos de línea).
 *   b) Lo tokeniza en palabras y signos, y genera unigramas + bigramas.
 *   c) Busca cada n-grama en el vocabulario del modelo (≈28.000 n-gramas
 *      seleccionados por chi² sobre ~151.000 textos reales humanos e IA).
 *   d) Calcula 24 rasgos estilométricos (burstiness, diversidad léxica, etc.).
 *   e) Aplica una regresión logística entrenada → puntaje z.
 *   f) Convierte z en probabilidad CALIBRADA (escalado de Platt sobre un
 *      conjunto de validación que el modelo nunca vio al entrenar).
 *   g) Compara contra umbrales elegidos para que, en validación, menos del 1%
 *      de los textos humanos sea acusado de IA (prioridad: cero falsas
 *      acusaciones). Entre ambos umbrales el veredicto es "no concluyente".
 *   h) Un segundo clasificador (multinomial) estima la familia de modelo.
 *   i) Como el modelo es lineal, cada decisión se puede descomponer en
 *      aportes exactos de cada n-grama, rasgo y oración → explicación real,
 *      no inventada.
 *
 * Si este archivo no existiera: la app sólo funcionaría con servidor PHP; en
 * el celular (sin PHP) el botón "Analizar" no podría producir resultados.
 * ============================================================================= */

/* Patrón UMD mínimo: permite usar el mismo archivo en el navegador
 * (window.MotorVAT) y en Node.js (require) para las pruebas de paridad.
 * Sin este envoltorio, o el navegador fallaría con "module is not defined",
 * o Node no podría importar el motor para verificar que coincide con PHP. */
(function (raiz, fabrica) {
  if (typeof module === 'object' && module.exports) {
    module.exports = fabrica();          // Entorno Node.js (pruebas automáticas).
  } else {
    raiz.MotorVAT = fabrica();           // Entorno navegador (uso normal).
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict'; // Modo estricto: convierte errores silenciosos (variables no declaradas) en excepciones visibles.

  /* ---------------------------------------------------------------------------
   * 1. EXPRESIONES REGULARES COMPARTIDAS
   * Deben ser IDÉNTICAS a las de PHP (src/Tokenizador.php) y a las del script
   * de entrenamiento (entrenamiento/features.py). Si cambiaran, el texto se
   * partiría distinto que en el entrenamiento y los pesos del modelo dejarían
   * de corresponder a los n-gramas → probabilidades erróneas.
   * ------------------------------------------------------------------------- */
  const RE_TOKEN = /[\p{L}\p{N}]+|[^\s\p{L}\p{N}]/gu;   // Palabra (letras/números) o signo suelto.
  const RE_PALABRA = /[\p{L}\p{N}]+/gu;                 // Sólo palabras (para estilometría).
  const RE_ORACION = /(?<=[.!?…])\s+|\n+/u;             // Corte de oración tras . ! ? … o salto de línea.
  const RE_DIGITOS = /^[0-9]+$/;                        // Números puros → se reemplazan por "<n>".
  const RE_VINETA = /^\s*([-*•]|\d+[.)])\s/;            // Línea de lista (viñeta o numerada).
  const RE_MARKDOWN = /^\s*#{1,6}\s|\*\*/;              // Encabezado Markdown o negrita **.

  /* ---------------------------------------------------------------------------
   * 2. LISTAS LÉXICAS (idénticas a features.py)
   * STOP = palabras funcionales en español e inglés; P1/P2 = pronombres de
   * primera y segunda persona. Si se modificaran sin reentrenar, los rasgos
   * "stop", "p1" y "p2" cambiarían de escala y sesgarían el veredicto.
   * ------------------------------------------------------------------------- */
  const STOP = new Set(('de la que el en y a los del se las por un para con no una su al lo como más pero sus le ya o este sí porque esta entre cuando muy sin sobre también me hasta hay donde quien desde todo nos durante todos uno les ni contra otros ese eso ante ellos e esto mí antes algunos qué unos yo otro otras otra él tanto esa estos mucho quienes nada muchos cual poco ella estar estas algunas algo nosotros es son fue ser ha han era ' +
    'the of and to a in is that it was for on are as with his they be at one have this from by or had not but what all were when we there can an your which their if do will each about how up out them then she many some so these would other into has more her two like him see time could no make than first been its who now people my made over did down only way find use may long very after').split(/\s+/));
  const P1 = new Set('yo me mi mis conmigo mío mía i my mine myself'.split(' '));
  const P2 = new Set('tú tu tus te ti usted ustedes vos you your yours yourself'.split(' '));

  /* Palabras indicadoras de idioma (sólo para mostrar el idioma y advertir si
   * no es español/inglés). Sin esto, un texto en francés se analizaría sin
   * aviso y el usuario creería que el resultado es igual de fiable. */
  const IDIOMA_ES = new Set('el la los las de que y en un una es por con para del se no su al lo como más pero muy también este esta porque cuando'.split(' '));
  const IDIOMA_EN = new Set('the of and to in is that it for on are as with be at this from by or not but have was were which their will'.split(' '));

  /* ---------------------------------------------------------------------------
   * 3. METADATOS LEGIBLES DE LOS 24 RASGOS ESTILOMÉTRICOS
   * El orden DEBE coincidir con modelo.densos.nombres. Cada entrada traduce un
   * número a una explicación humana. Si faltara, la interfaz mostraría
   * identificadores crudos ("sl_cv") imposibles de entender para el usuario.
   * ------------------------------------------------------------------------- */
  const RASGOS = {
    sl_mean: ['Longitud media de oración', 'palabras por oración'],
    sl_cv: ['Variabilidad entre oraciones (burstiness)', 'coef. de variación; los humanos alternan oraciones cortas y largas'],
    mattr: ['Diversidad léxica (MATTR)', 'proporción de palabras distintas en ventanas de 50'],
    wlen: ['Longitud media de palabra', 'letras por palabra'],
    long: ['Palabras largas (≥7 letras)', 'proporción'],
    comma: ['Densidad de comas', 'comas por palabra'],
    semi: ['Punto y coma', 'por palabra'],
    colon: ['Dos puntos', 'por palabra'],
    dash: ['Rayas (— / –)', 'por palabra'],
    paren: ['Paréntesis', 'por palabra'],
    quote: ['Comillas', 'por palabra'],
    quest: ['Preguntas', 'signos ? por palabra'],
    excl: ['Exclamaciones', 'signos ! por palabra'],
    ellip: ['Puntos suspensivos', 'por palabra'],
    digit: ['Cifras y números', 'proporción de palabras con dígitos'],
    bullet: ['Listas con viñetas', 'proporción de líneas'],
    md: ['Formato Markdown (## / **)', 'proporción de líneas'],
    para: ['Densidad de párrafos/líneas', 'líneas cada 100 palabras'],
    stop: ['Palabras funcionales', 'proporción (de, que, the, of…)'],
    p1: ['Primera persona (yo, mi, I, my)', 'proporción'],
    p2: ['Segunda persona (tú, usted, you)', 'proporción'],
    contr: ['Apóstrofos / contracciones', 'por palabra'],
    startrep: ['Repetición de inicios de oración', 'proporción de oraciones que empiezan igual que otra'],
    emoji: ['Emojis', 'por palabra']
  };

  /* Nombres comerciales de cada familia de modelos que el clasificador conoce.
   * Sin este mapa la atribución mostraría claves internas ("anthropic"). */
  const FAMILIAS = {
    openai: 'ChatGPT / GPT (OpenAI)',
    anthropic: 'Claude (Anthropic)',
    google: 'Gemini (Google)',
    meta: 'Llama (Meta)',
    mistral: 'Mistral / Mixtral',
    cohere: 'Command (Cohere)',
    otros: 'Otros (Yandex, GigaChat, AI21, Qwen, BLOOM…)'
  };

  /* ---------------------------------------------------------------------------
   * 4. MARCADORES DISCURSIVOS CONOCIDOS (sólo informativos)
   * Frases que la literatura y la comunidad asocian a textos de LLM. NO suman
   * al puntaje (el puntaje lo decide únicamente el modelo entrenado, para no
   * meter "chamuyo" heurístico); se muestran como contexto adicional. Si se
   * quitaran, el informe perdería una pista legible, pero el veredicto sería
   * exactamente el mismo.
   * ------------------------------------------------------------------------- */
  const MARCADORES = [
    // [frase en minúsculas, familia asociada ('general' = cualquier LLM), descripción]
    ['cabe destacar', 'general', 'Conector enfático típico de redacción generada'],
    ['es importante destacar', 'general', 'Fórmula de énfasis muy frecuente en LLM'],
    ['es importante señalar', 'general', 'Fórmula de énfasis muy frecuente en LLM'],
    ['es fundamental', 'general', 'Valoración genérica recurrente'],
    ['juega un papel', 'general', 'Colocación sobrerrepresentada en IA ("juega un papel crucial")'],
    ['desempeña un papel', 'general', 'Colocación sobrerrepresentada en IA'],
    ['en el mundo actual', 'general', 'Apertura genérica típica'],
    ['en la era digital', 'general', 'Apertura genérica típica'],
    ['en conclusión', 'general', 'Cierre de ensayo escolar, muy típico de LLM'],
    ['en resumen', 'general', 'Resumen final automático'],
    ['en definitiva', 'general', 'Cierre recapitulativo'],
    ['a lo largo de la historia', 'general', 'Apertura histórica genérica'],
    ['sin lugar a dudas', 'general', 'Muletilla enfática'],
    ['una amplia gama', 'general', 'Colocación frecuente en IA'],
    ['en última instancia', 'general', 'Calco del inglés "ultimately"'],
    ['no solo', 'general', 'Estructura "no solo… sino también", sobreusada por LLM'],
    ['un testimonio de', 'general', 'Calco de "a testament to"'],
    ['profundizar en', 'openai', 'Equivalente de "delve into", muy asociado a GPT'],
    ['tapiz', 'openai', 'Metáfora "tapestry", muy asociada a GPT-4'],
    ['espero que esto te ayude', 'openai', 'Cierre conversacional de asistente'],
    ['¡claro!', 'general', 'Apertura de asistente conversacional'],
    ['aquí tienes', 'general', 'Apertura de asistente conversacional'],
    ['vale la pena señalar', 'anthropic', 'Fórmula de matización frecuente en Claude'],
    ['matiz', 'anthropic', 'Léxico de matización frecuente en Claude'],
    ['genuinamente', 'anthropic', 'Adverbio frecuente en Claude'],
    ['en esencia', 'google', 'Fórmula de síntesis frecuente en Gemini'],
    ['puntos clave', 'google', 'Encabezado de resumen frecuente en Gemini'],
    ['desglose', 'google', 'Introducción "aquí tienes un desglose", frecuente en Gemini'],
    ["it's important to note", 'general', 'Fórmula de énfasis muy frecuente en LLM'],
    ['it is important to note', 'general', 'Fórmula de énfasis muy frecuente en LLM'],
    ['plays a crucial role', 'general', 'Colocación sobrerrepresentada en IA'],
    ['plays a vital role', 'general', 'Colocación sobrerrepresentada en IA'],
    ["in today's", 'general', 'Apertura genérica ("In today\'s fast-paced world")'],
    ['delve', 'openai', 'Verbo "delve" disparado por GPT-4 desde 2023'],
    ['tapestry', 'openai', 'Metáfora muy asociada a GPT-4'],
    ['testament to', 'general', 'Colocación sobrerrepresentada en IA'],
    ['in conclusion', 'general', 'Cierre de ensayo escolar'],
    ['furthermore', 'general', 'Conector formal sobreusado'],
    ['moreover', 'general', 'Conector formal sobreusado'],
    ['a myriad of', 'general', 'Colocación frecuente en IA'],
    ['ever-evolving', 'general', 'Adjetivo típico de IA'],
    ['intricate', 'general', 'Adjetivo sobrerrepresentado en IA'],
    ['realm', 'general', 'Sustantivo sobrerrepresentado en IA'],
    ['i hope this helps', 'openai', 'Cierre conversacional de asistente'],
    ["i'd be happy to", 'anthropic', 'Apertura frecuente en Claude'],
    ['worth noting', 'anthropic', 'Matización frecuente en Claude'],
    ['nuanced', 'anthropic', 'Léxico de matización frecuente en Claude'],
    ["here's a breakdown", 'google', 'Introducción frecuente en Gemini'],
    ['key takeaways', 'google', 'Encabezado de resumen frecuente en Gemini'],
    ['in essence', 'google', 'Fórmula de síntesis frecuente en Gemini']
  ];

  /* Caché del modelo preparado (índice término → posición). Construirlo cuesta
   * ~30 ms; sin caché se reconstruiría en cada análisis, haciendo la app más
   * lenta en celulares de gama baja. */
  let modeloPreparado = null;

  /* Desplazamiento de la escala por oración (ver analizarOraciones). Debe ser
   * igual al de src/ClasificadorVAT.php para que ambos motores resalten igual. */
  const DESPLAZAMIENTO_ORACION = 1.0;

  /* ---------------------------------------------------------------------------
   * prepararModelo(modelo)
   * Convierte el arreglo "vocab" del JSON en un Map para búsquedas O(1).
   * Sin este índice habría que recorrer 28.000 términos por cada n-grama del
   * texto (millones de comparaciones) y el navegador se congelaría.
   * ------------------------------------------------------------------------- */
  function prepararModelo(modelo) {
    if (modeloPreparado && modeloPreparado.fuente === modelo) return modeloPreparado; // Reutiliza la caché.
    if (!modelo || !Array.isArray(modelo.vocab)) {
      // Sin modelo no hay nada que calcular: se lanza un error explícito en
      // lugar de devolver probabilidades inventadas.
      throw new Error('Modelo VAT no cargado (falta modelo/vat-modelo.js).');
    }
    const indice = new Map();
    for (let i = 0; i < modelo.vocab.length; i++) indice.set(modelo.vocab[i], i);
    modeloPreparado = { fuente: modelo, indice: indice, F: modelo.familias.length };
    return modeloPreparado;
  }

  /* ---------------------------------------------------------------------------
   * normalizar(texto)
   * Unicode NFC une letras con tildes compuestas ("e" + "´" → "é") y unifica
   * saltos de línea de Windows/Mac. Sin esto, el mismo texto copiado desde
   * Word o desde un celular produciría tokens distintos y otro resultado.
   * ------------------------------------------------------------------------- */
  function normalizar(texto) {
    return String(texto || '').normalize('NFC').replace(/\r\n?/g, '\n');
  }

  /* ---------------------------------------------------------------------------
   * tokenizar(texto)
   * Pasa a minúsculas, convierte saltos de línea en el token "¶" (la
   * estructura en párrafos es una señal forense) y reemplaza números puros por
   * "<n>" (para que "2019" y "2024" cuenten como el mismo patrón). Sin esta
   * función no existirían los n-gramas sobre los que se entrenó el modelo.
   * ------------------------------------------------------------------------- */
  function tokenizar(texto) {
    const base = texto.toLowerCase().replace(/\n+/g, ' ¶ ');
    const crudos = base.match(RE_TOKEN) || [];   // match devuelve null si no hay tokens → se usa [] para no romper.
    return crudos.map(function (t) { return RE_DIGITOS.test(t) ? '<n>' : t; });
  }

  /* ---------------------------------------------------------------------------
   * ngramas(tokens)
   * Devuelve unigramas y bigramas ("es importante"). Los bigramas capturan
   * colocaciones típicas de la IA que una palabra suelta no revela. Si sólo
   * se usaran unigramas, se perdería ~2 puntos de AUC según el entrenamiento.
   * ------------------------------------------------------------------------- */
  function ngramas(tokens) {
    const salida = tokens.slice();
    for (let i = 0; i < tokens.length - 1; i++) salida.push(tokens[i] + ' ' + tokens[i + 1]);
    return salida;
  }

  /* Cuenta apariciones no solapadas de una subcadena (igual que str.count de
   * Python y substr_count de PHP). Sin esto los rasgos de puntuación darían
   * valores distintos entre motores. */
  function contar(texto, sub) { return texto.split(sub).length - 1; }

  /* Detecta emojis por rango de código Unicode (mismo criterio en los tres
   * lenguajes). Sin esto, textos con emojis (típicos de chats) no se
   * distinguirían de prosa formal. */
  function esEmoji(cp) { return (cp >= 0x1F300 && cp <= 0x1FAFF) || (cp >= 0x2600 && cp <= 0x27BF); }

  /* ---------------------------------------------------------------------------
   * dividirOraciones(texto)
   * Corta tras . ! ? … o saltos de línea y descarta fragmentos sin palabras.
   * Sin este paso no se podrían medir la burstiness, ni señalar oraciones
   * concretas como evidencia en el informe.
   * ------------------------------------------------------------------------- */
  function dividirOraciones(texto) {
    return texto.split(RE_ORACION).map(function (s) { return s.trim(); })
      .filter(function (s) { RE_PALABRA.lastIndex = 0; return /[\p{L}\p{N}]/u.test(s); });
  }

  /* ---------------------------------------------------------------------------
   * calcularDensos(texto)
   * Calcula los 24 rasgos estilométricos en el mismo orden que el modelo.
   * Cada cálculo replica features.py línea a línea. Si se omitiera, el modelo
   * perdería las señales globales (ritmo, puntuación, formato) que complementan
   * a los n-gramas y que son clave en textos de IA "parafraseados".
   * ------------------------------------------------------------------------- */
  function calcularDensos(texto) {
    const bajo = texto.toLowerCase();
    const palabras = bajo.match(RE_PALABRA) || [];
    const n = Math.max(palabras.length, 1);              // Evita división por cero en textos vacíos.
    const oraciones = dividirOraciones(texto);
    let largos = oraciones.map(function (s) { return (s.match(RE_PALABRA) || []).length; });
    if (largos.length === 0) largos = [n];               // Texto sin cortes: una sola "oración".
    const media = largos.reduce(function (a, b) { return a + b; }, 0) / largos.length;
    const desv = Math.sqrt(largos.reduce(function (a, x) { return a + (x - media) * (x - media); }, 0) / largos.length);

    // MATTR: promedio de type-token ratio en ventanas de 50 palabras (paso 10).
    // Se usa en vez del TTR simple porque éste cae con la longitud del texto y
    // penalizaría injustamente a textos largos.
    let mattr;
    if (palabras.length < 50) {
      mattr = new Set(palabras).size / n;
    } else {
      let suma = 0, k = 0;
      for (let i = 0; i < palabras.length - 49; i += 10) { suma += new Set(palabras.slice(i, i + 50)).size / 50; k++; }
      mattr = suma / k;
    }

    const lineas = texto.split('\n').filter(function (l) { return l.trim() !== ''; });
    const nl = Math.max(lineas.length, 1);
    const primeras = oraciones.map(function (s) { return (s.toLowerCase().match(RE_PALABRA) || [''])[0]; });

    let emojis = 0;
    for (const ch of texto) { if (esEmoji(ch.codePointAt(0))) emojis++; } // for..of recorre puntos de código reales (emojis de 2 unidades UTF-16).

    const f = {
      sl_mean: Math.min(media, 80),
      sl_cv: media ? desv / media : 0,
      mattr: mattr,
      wlen: palabras.reduce(function (a, w) { return a + Array.from(w).length; }, 0) / n,
      long: palabras.filter(function (w) { return Array.from(w).length >= 7; }).length / n,
      comma: contar(texto, ',') / n,
      semi: contar(texto, ';') / n,
      colon: contar(texto, ':') / n,
      dash: (contar(texto, '—') + contar(texto, '–')) / n,
      paren: contar(texto, '(') / n,
      quote: ['"', '“', '”', '«', '»'].reduce(function (a, c) { return a + contar(texto, c); }, 0) / n,
      quest: contar(texto, '?') / n,
      excl: contar(texto, '!') / n,
      ellip: (contar(texto, '…') + contar(texto, '...')) / n,
      digit: palabras.filter(function (w) { return /[0-9]/.test(w); }).length / n,
      bullet: lineas.filter(function (l) { return RE_VINETA.test(l); }).length / nl,
      md: lineas.filter(function (l) { return RE_MARKDOWN.test(l); }).length / nl,
      para: lineas.length / n * 100,
      stop: palabras.filter(function (w) { return STOP.has(w); }).length / n,
      p1: palabras.filter(function (w) { return P1.has(w); }).length / n,
      p2: palabras.filter(function (w) { return P2.has(w); }).length / n,
      contr: (contar(texto, "'") + contar(texto, '’')) / n,
      startrep: primeras.length >= 2 ? 1 - new Set(primeras).size / primeras.length : 0,
      emoji: emojis / n
    };
    return { valores: f, palabras: palabras, oraciones: oraciones, lineas: lineas };
  }

  /* ---------------------------------------------------------------------------
   * puntuar(texto, modelo)
   * Núcleo matemático: vector TF sublineal (1+ln c) normalizado L2 sobre los
   * n-gramas del vocabulario + rasgos estandarizados (z-score recortado a ±5)
   * → producto punto con los pesos. Devuelve z binario, z por familia y los
   * aportes individuales (para explicar). Si esta función fallara, no habría
   * probabilidad: por eso cualquier error se propaga a la interfaz.
   * ------------------------------------------------------------------------- */
  function puntuar(texto, modelo) {
    const pm = prepararModelo(modelo);
    const F = pm.F, E = modelo.escala;                 // Pesos guardados como enteros ×1000 para reducir el tamaño.
    const tokens = tokenizar(texto);
    const cuentas = new Map();
    for (const g of ngramas(tokens)) {
      const i = pm.indice.get(g);
      if (i !== undefined) cuentas.set(i, (cuentas.get(i) || 0) + 1); // Sólo n-gramas conocidos: el resto no tiene peso.
    }
    let norma = 0;
    cuentas.forEach(function (c) { const v = 1 + Math.log(c); norma += v * v; });
    norma = Math.sqrt(norma) || 1;                     // Si no hay n-gramas conocidos, norma 1 para no dividir por 0.

    let z = modelo.sesgo;
    const zf = modelo.sesgo_fam.slice();
    const aportesTerm = [];
    cuentas.forEach(function (c, i) {
      const v = (1 + Math.log(c)) / norma;
      const a = v * modelo.wb[i] / E;
      z += a;
      for (let k = 0; k < F; k++) zf[k] += v * modelo.wf[i * F + k] / E;
      aportesTerm.push({ termino: modelo.vocab[i], aporte: a, veces: c });
    });

    const D = modelo.densos;
    const dens = calcularDensos(texto);
    const aportesRasgo = [];
    D.nombres.forEach(function (nombre, j) {
      const x = dens.valores[nombre];
      const xs = Math.max(-5, Math.min(5, (x - D.media[j]) / D.desv[j]));  // Recorte: un valor extremo no puede dominar todo el veredicto.
      const a = xs * D.wb[j] / E;
      z += a;
      for (let k = 0; k < F; k++) zf[k] += xs * D.wf[j * F + k] / E;
      aportesRasgo.push({ id: nombre, valor: x, z: xs, aporte: a, media_humano: D.media_humano[j], media_ia: D.media_ia[j] });
    });
    return { z: z, zf: zf, aportesTerm: aportesTerm, aportesRasgo: aportesRasgo, dens: dens, tokens: tokens, conocidos: cuentas.size };
  }

  /* Escalado de Platt: transforma el puntaje bruto en probabilidad calibrada.
   * Sin calibrar, un 0,8 del modelo no significaría "80% de los textos con este
   * puntaje son IA"; la calibración hace que el porcentaje sea honesto. */
  function calibrar(z, platt) { return 1 / (1 + Math.exp(-(platt.a * z + platt.b))); }

  /* Softmax numéricamente estable (resta el máximo para evitar Infinity). Sin
   * él, las probabilidades por familia no sumarían 100%. */
  function softmax(v) {
    const m = Math.max.apply(null, v);
    const e = v.map(function (x) { return Math.exp(x - m); });
    const s = e.reduce(function (a, b) { return a + b; }, 0);
    return e.map(function (x) { return x / s; });
  }

  /* Detecta idioma contando palabras funcionales. Si no se detectara, el
   * usuario no sabría que un texto en otro idioma tiene fiabilidad reducida. */
  function detectarIdioma(palabras) {
    let es = 0, en = 0;
    palabras.forEach(function (w) { if (IDIOMA_ES.has(w)) es++; if (IDIOMA_EN.has(w)) en++; });
    const total = Math.max(palabras.length, 1);
    if ((es + en) / total < 0.08) return { codigo: 'otro', nombre: 'No identificado', soportado: false };
    return es >= en ? { codigo: 'es', nombre: 'Español', soportado: true } : { codigo: 'en', nombre: 'Inglés', soportado: true };
  }

  /* ---------------------------------------------------------------------------
   * analizarOraciones(oraciones, modelo)
   * Puntúa cada oración con los mismos pesos del modelo (suma de pesos de sus
   * n-gramas / √n). Así se señala DÓNDE está la evidencia. Sin esto, el informe
   * sólo daría un número global sin poder citar oraciones concretas, y no se
   * podrían detectar textos mixtos (parte humana, parte IA).
   * ------------------------------------------------------------------------- */
  function analizarOraciones(oraciones, modelo) {
    const pm = prepararModelo(modelo);
    return oraciones.map(function (texto, indice) {
      const gs = ngramas(tokenizar(texto));
      let suma = 0, vistos = 0;
      const top = [];
      gs.forEach(function (g) {
        const i = pm.indice.get(g);
        if (i !== undefined) { const w = modelo.wb[i] / modelo.escala; suma += w; vistos++; top.push([g, w]); }
      });
      // DESPLAZAMIENTO_ORACION centra la escala: medido sobre 6.000 textos del corpus, la oración humana
      // mediana queda en ≈ -0,5 y la de IA en ≈ +0,5. Sin él, casi todas las oraciones saldrían "humanas".
      // tanh acota el resultado a (-1, 1) para pintar el resaltado.
      const puntaje = Math.tanh(suma / Math.sqrt(gs.length + 1) + DESPLAZAMIENTO_ORACION);
      top.sort(function (a, b) { return b[1] - a[1]; });
      return {
        indice: indice, texto: texto, puntaje: puntaje,
        nivel: puntaje >= 0.35 ? 'ia' : (puntaje <= -0.35 ? 'humano' : 'neutro'),
        claves: top.filter(function (t) { return t[1] > 0; }).slice(0, 3).map(function (t) { return t[0]; })
      };
    });
  }

  /* Busca los marcadores discursivos conocidos (informativos). Usa búsqueda
   * por subcadena sobre el texto en minúsculas; sin esto el informe no podría
   * listar fórmulas típicas encontradas. */
  function detectarMarcadores(texto) {
    const bajo = texto.toLowerCase();
    const res = [];
    MARCADORES.forEach(function (m) {
      const veces = contar(bajo, m[0]);
      if (veces > 0) res.push({ frase: m[0], familia: m[1], descripcion: m[2], ocurrencias: veces });
    });
    return res;
  }

  /* ---------------------------------------------------------------------------
   * estimarPrompt(...)
   * Reconstruye el pedido más probable a partir del género, tema, extensión,
   * estructura y tono observados. Es una INFERENCIA (se indica así en la UI):
   * ningún sistema puede recuperar el prompt exacto. Sin esta función faltaría
   * la sección "posible prompt" solicitada.
   * ------------------------------------------------------------------------- */
  /* Palabras de estilo/relleno que los modelos repiten pero que NO son el tema
   * (p. ej. "crucial", "importante", "however"). Sin esta lista, el posible
   * prompt diría "sobre crucial, importante…" en vez del tema real.
   * Debe ser idéntica a Explicador::GENERICOS en PHP para mantener la paridad. */
  const GENERICOS = new Set(["about", "actual", "actuales", "additionally", "además", "aspecto", "aspectos", "conclusion", "conclusión", "could", "crucial", "cruciales", "diversas", "diversos", "ejemplo", "embargo", "ensure", "esencial", "esenciales", "essential", "example", "forma", "fundamental", "fundamentales", "furthermore", "general", "however", "important", "importante", "importantes", "manera", "moreover", "mundo", "overall", "provide", "resumen", "should", "siempre", "significant", "significativa", "significativas", "significativo", "significativos", "también", "their", "there", "these", "those", "today", "través", "various", "which", "world", "would"]);

  function estimarPrompt(texto, dens, idioma) {
    const v = dens.valores, palabras = dens.palabras, bajo = texto.toLowerCase();
    // Tema: palabras de contenido más frecuentes (≥5 letras, no funcionales).
    const freq = new Map();
    palabras.forEach(function (w) { if (Array.from(w).length >= 5 && !STOP.has(w) && !GENERICOS.has(w) && !/[0-9]/.test(w)) freq.set(w, (freq.get(w) || 0) + 1); });
    const tema = Array.from(freq.entries()).sort(function (a, b) { return b[1] - a[1] || (a[0] < b[0] ? -1 : 1); }).slice(0, 4).map(function (e) { return e[0]; });

    // Género: reglas transparentes basadas en los rasgos ya medidos.
    let genero = 'texto expositivo';
    const tiene = function (lista) { return lista.some(function (w) { return bajo.indexOf(w) !== -1; }); };
    if (v.md > 0.15 || v.bullet > 0.2) genero = 'guía o explicación estructurada con títulos y viñetas';
    else if (v.quote > 0.01 && v.p1 > 0.01) genero = 'relato o cuento narrativo';
    else if (tiene(['estudio', 'investigación', 'análisis', 'metodología', 'study', 'research', 'analysis', 'hypothesis'])) genero = 'ensayo o párrafo académico';
    else if (tiene(['según', 'declaró', 'informó', 'anunció', 'said', 'reported', 'according to'])) genero = 'noticia periodística';
    else if (tiene(['recomiendo', 'producto', 'hotel', 'servicio', 'calidad', 'recommend', 'product', 'service'])) genero = 'reseña u opinión de producto/servicio';
    else if (v.p2 > 0.015) genero = 'texto instructivo dirigido al lector';
    else if (tiene(['en conclusión', 'en resumen', 'in conclusion', 'por otro lado', 'on the other hand'])) genero = 'ensayo argumentativo';

    const n = palabras.length;
    const largo = n < 80 ? 'breve (' + Math.max(10, Math.round(n / 10) * 10) + ' palabras aprox.)' : 'de unas ' + Math.round(n / 50) * 50 + ' palabras';
    const tono = (v.excl > 0.01 || v.emoji > 0 || v.contr > 0.02) ? 'cercano/informal' : (v.wlen > 5.2 ? 'formal y técnico' : 'neutro e informativo');
    const parrafos = dens.lineas.length;
    const estructura = v.md > 0.15 ? ', con encabezados y listas' : (parrafos >= 3 ? ', organizado en ' + parrafos + ' párrafos con introducción y conclusión' : '');
    const lenguaje = idioma.codigo === 'en' ? ' en inglés' : '';
    const temaTxt = tema.length ? tema.join(', ') : 'el tema del texto';
    return {
      texto: 'Escribe un ' + genero + ' ' + largo + lenguaje + ' sobre "' + temaTxt + '", con tono ' + tono + estructura + '.',
      genero: genero, tema: tema, tono: tono, longitud: n,
      nota: 'Reconstrucción inferida a partir de género, tema, extensión, estructura y tono. Ningún detector puede recuperar el prompt literal.'
    };
  }

  /* Formatea números para la interfaz (coma decimal). Sin esto se mostrarían
   * decimales larguísimos como 0.36788944. */
  function fmt(x, d) { return (Math.round(x * Math.pow(10, d)) / Math.pow(10, d)).toString().replace('.', ','); }

  /* ---------------------------------------------------------------------------
   * analizar(texto, modelo)  — FUNCIÓN PÚBLICA PRINCIPAL
   * Orquesta todo el protocolo y devuelve el mismo objeto JSON que
   * api/analizar.php, de modo que la interfaz no distingue qué motor respondió.
   * ------------------------------------------------------------------------- */
  function analizar(textoOriginal, modelo) {
    const t0 = Date.now();
    const texto = normalizar(textoOriginal).trim();
    if (!texto) return { ok: false, error: 'El texto está vacío.' };   // Sin esta guarda, un texto vacío daría una probabilidad sin sentido.
    if (texto.length > 60000) return { ok: false, error: 'Texto demasiado largo (máximo 60.000 caracteres).' }; // Protege celulares de congelarse.

    const r = puntuar(texto, modelo);
    const dens = r.dens;
    const U = modelo.umbrales;
    const nPal = dens.palabras.length;
    const p = calibrar(r.z, modelo.platt);
    const idioma = detectarIdioma(dens.palabras);
    const famProb = softmax(r.zf);
    const oraciones = analizarOraciones(dens.oraciones, modelo);

    /* --- Veredicto conservador --------------------------------------------
     * Sólo se afirma "IA" si p supera el umbral calibrado para ≤1% de falsos
     * positivos en validación. Si se usara 50% como corte, aprox. 6 de cada 100
     * textos humanos serían acusados injustamente. */
    let codigo, titulo, detalle;
    if (nPal < U.min_palabras) {
      codigo = 'insuficiente'; titulo = 'Texto demasiado corto';
      detalle = 'Con menos de ' + U.min_palabras + ' palabras ningún detector es fiable. Pegá al menos 1–2 párrafos (ideal: 150+ palabras).';
    } else if (p >= U.ia) {
      codigo = 'ia'; titulo = 'Generado por IA';
      detalle = 'La probabilidad calibrada supera el umbral estricto (' + fmt(U.ia * 100, 1) + '%) fijado para no acusar a textos humanos.';
    } else if (p <= U.humano) {
      codigo = 'humano'; titulo = 'Escrito por un humano';
      detalle = 'El texto no presenta el patrón estadístico de los modelos de lenguaje.';
    } else {
      codigo = 'no_concluyente'; titulo = 'No concluyente';
      detalle = 'Hay señales mezcladas: puede ser texto humano muy formal, IA editada/parafraseada o un texto mixto. VAT prefiere no acusar sin pruebas suficientes.';
    }

    /* Confianza: combina la distancia al umbral y la longitud (más palabras =
     * más evidencia). Sin ella, un veredicto con 45 palabras parecería tan
     * sólido como uno con 800. */
    const distancia = codigo === 'ia' ? (p - U.ia) / (1 - U.ia) : codigo === 'humano' ? (U.humano - p) / U.humano : 0;
    const factorLongitud = Math.min(1, nPal / 250);
    const confianza = codigo === 'insuficiente' ? 0.1 : Math.max(0.05, Math.min(0.99, (0.55 + 0.45 * distancia) * (0.5 + 0.5 * factorLongitud)));

    /* --- Texto mixto: proporción de oraciones con señal IA y humana ------- */
    const nIA = oraciones.filter(function (o) { return o.nivel === 'ia'; }).length;
    const nHum = oraciones.filter(function (o) { return o.nivel === 'humano'; }).length;
    const mixto = oraciones.length >= 6 && nIA / oraciones.length >= 0.25 && nHum / oraciones.length >= 0.25;

    /* --- Atribución de familia (sólo tiene sentido si hay señal de IA) ---- */
    const familias = modelo.familias.map(function (id, k) { return { id: id, nombre: FAMILIAS[id] || id, prob: famProb[k] }; })
      .sort(function (a, b) { return b.prob - a.prob; });
    const atribDisponible = codigo === 'ia' || (codigo === 'no_concluyente' && p >= 0.5);
    const confiable = familias[0].prob >= 0.5 && familias[0].prob - familias[1].prob >= 0.2;

    /* --- Explicación: aportes reales del modelo --------------------------- */
    const terms = r.aportesTerm.slice().sort(function (a, b) { return b.aporte - a.aporte; });
    const ngIA = terms.filter(function (t) { return t.aporte > 0; }).slice(0, 12).map(function (t) { return { termino: t.termino.replace(/¶/g, '↵'), peso: t.aporte, veces: t.veces }; });
    const ngHum = terms.filter(function (t) { return t.aporte < 0; }).reverse().slice(0, 12).map(function (t) { return { termino: t.termino.replace(/¶/g, '↵'), peso: t.aporte, veces: t.veces }; });
    const rasgos = r.aportesRasgo.map(function (a) {
      const meta = RASGOS[a.id] || [a.id, ''];
      return {
        id: a.id, nombre: meta[0], unidad: meta[1], valor: a.valor, media_humano: a.media_humano, media_ia: a.media_ia,
        aporte: a.aporte, direccion: a.aporte >= 0 ? 'ia' : 'humano',
        // Posición del valor en escala 0–1 entre las medias humano/IA (para la barra visual).
        escala: Math.max(0, Math.min(1, 0.5 + a.z / 6))
      };
    }).sort(function (a, b) { return Math.abs(b.aporte) - Math.abs(a.aporte); });

    const sumaTerm = terms.reduce(function (s, t) { return s + t.aporte; }, 0);
    const sumaRasgo = r.aportesRasgo.reduce(function (s, a) { return s + a.aporte; }, 0);

    /* Evidencias en lenguaje natural, citando oraciones y valores medidos. */
    const evidencias = [];
    const topOr = oraciones.slice().sort(function (a, b) { return b.puntaje - a.puntaje; });
    topOr.filter(function (o) { return o.nivel === 'ia'; }).slice(0, 3).forEach(function (o) {
      evidencias.push({ tipo: 'ia', texto: 'Oración ' + (o.indice + 1) + ': «' + recortar(o.texto, 180) + '» concentra patrones típicos de IA' + (o.claves.length ? ' (n-gramas: ' + o.claves.map(function (c) { return '"' + c.replace(/¶/g, '↵') + '"'; }).join(', ') + ').' : '.') });
    });
    rasgos.slice(0, 4).forEach(function (a) {
      evidencias.push({ tipo: a.direccion, texto: a.nombre + ' = ' + fmt(a.valor, 3) + ' (media en textos humanos ' + fmt(a.media_humano, 3) + ' · en IA ' + fmt(a.media_ia, 3) + ') → empuja hacia ' + (a.direccion === 'ia' ? 'IA' : 'HUMANO') + '.' });
    });
    topOr.slice().reverse().filter(function (o) { return o.nivel === 'humano'; }).slice(0, 2).forEach(function (o) {
      evidencias.push({ tipo: 'humano', texto: 'Oración ' + (o.indice + 1) + ': «' + recortar(o.texto, 180) + '» tiene rasgos más propios de escritura humana.' });
    });

    const proceso = [
      { paso: 'Normalización', detalle: 'Unicode NFC y saltos de línea unificados: ' + texto.length + ' caracteres, ' + nPal + ' palabras, ' + dens.oraciones.length + ' oraciones, ' + dens.lineas.length + ' párrafos.' },
      { paso: 'Tokenización y n-gramas', detalle: r.tokens.length + ' tokens → ' + r.conocidos + ' n-gramas distintos reconocidos en el vocabulario forense (' + modelo.vocab.length.toLocaleString('es') + ' entradas).' },
      { paso: 'Estilometría', detalle: '24 rasgos medidos (ritmo, diversidad léxica, puntuación, formato, pronombres). Aporte total al puntaje: ' + fmt(sumaRasgo, 2) + '.' },
      { paso: 'Modelo léxico', detalle: 'Regresión logística entrenada con ~151.000 textos reales. Aporte de n-gramas: ' + fmt(sumaTerm, 2) + '. Puntaje bruto z = ' + fmt(r.z, 3) + '.' },
      { paso: 'Calibración', detalle: 'Escalado de Platt → probabilidad IA = ' + fmt(p * 100, 1) + '%. Umbrales: IA ≥ ' + fmt(U.ia * 100, 1) + '%, humano ≤ ' + fmt(U.humano * 100, 1) + '%.' },
      { paso: 'Análisis por oración', detalle: nIA + ' oraciones con señal IA, ' + nHum + ' con señal humana, ' + (oraciones.length - nIA - nHum) + ' neutras.' + (mixto ? ' Distribución compatible con texto MIXTO.' : '') },
      { paso: 'Atribución', detalle: atribDisponible ? 'Clasificador multinomial de 7 familias: ' + familias[0].nombre + ' ' + fmt(familias[0].prob * 100, 1) + '%.' : 'No se atribuye modelo porque no hay evidencia suficiente de IA.' }
    ];

    return {
      ok: true, motor: 'javascript', version: modelo.version,
      idioma: idioma,
      estadisticas: { palabras: nPal, caracteres: texto.length, oraciones: dens.oraciones.length, parrafos: dens.lineas.length },
      probabilidad_ia: p, probabilidad_humano: 1 - p, puntaje_z: r.z,
      veredicto: { codigo: codigo, titulo: titulo, detalle: detalle, confianza: confianza },
      mixto: { posible: mixto, detalle: mixto ? 'Se detectan bloques con señal IA y bloques con señal humana: el texto podría combinar partes escritas por una persona y partes generadas.' : '' },
      atribucion: { disponible: atribDisponible, confiable: confiable, familias: familias,
        nota: confiable ? 'Atribución con margen claro sobre la segunda opción.' : 'Atribución orientativa: la diferencia entre familias es pequeña. Modelos no vistos en el entrenamiento (p. ej. DeepSeek, Grok) se asignan a la familia más parecida.' },
      rasgos: rasgos, ngramas: { ia: ngIA, humano: ngHum }, oraciones: oraciones,
      evidencias: evidencias, marcadores: detectarMarcadores(texto),
      prompt_estimado: estimarPrompt(texto, dens, idioma), proceso: proceso,
      calibracion: { umbral_ia: U.ia, umbral_humano: U.humano, metricas: modelo.metricas },
      tiempo_ms: Date.now() - t0
    };
  }

  /* Recorta citas largas para que el informe sea legible en pantallas chicas. */
  function recortar(s, max) { return s.length > max ? s.slice(0, max - 1) + '…' : s; }

  // API pública del motor. Lo demás queda privado para evitar usos indebidos.
  return { analizar: analizar, puntuar: puntuar, calibrar: calibrar, softmax: softmax, calcularDensos: calcularDensos, normalizar: normalizar, tokenizar: tokenizar };
});
