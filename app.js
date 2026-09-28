/**
 * app.js — Lógica de la interfaz de VAT.
 *
 * Todo este archivo funciona con async/await y fetch() contra detector.php.
 * No hay ninguna librería externa: es JavaScript nativo a propósito, para
 * mantener el proyecto liviano y fácil de auditar función por función.
 */

// --- Referencias a elementos del DOM -----------------------------------
// Guardarlas una sola vez al cargar el script evita tener que volver a
// buscarlas (document.getElementById) cada vez que se ejecuta una función,
// lo cual sería más lento y más repetitivo.
const areaEntrada        = document.getElementById('texto-entrada');
const contadorCaracteres = document.getElementById('contador-caracteres');
const btnAnalizar        = document.getElementById('btn-analizar');
const btnHumanizar       = document.getElementById('btn-humanizar');
const mensajeError       = document.getElementById('mensaje-error');

const estadoVacio        = document.getElementById('estado-vacio');
const resultadoDeteccion = document.getElementById('resultado-deteccion');
const resultadoHumanizado = document.getElementById('resultado-humanizado');

const medidorProgreso = document.getElementById('medidor-progreso');
const medidorValor    = document.getElementById('medidor-valor');
const medidorEtiqueta = document.getElementById('medidor-etiqueta');
const avisoDeteccion  = document.getElementById('aviso-deteccion');

const textoHumanizado = document.getElementById('texto-humanizado');
const btnCopiar       = document.getElementById('btn-copiar');

// Circunferencia del anillo SVG (2 * π * radio, con radio=52 en el SVG).
// Se usa para convertir un porcentaje en el stroke-dashoffset que "dibuja"
// el arco de progreso. Si este número no coincidiera con el radio real
// del <circle> en el HTML, el anillo se vería con el arco mal calculado
// (por ejemplo, terminando antes o después de donde debería).
const CIRCUNFERENCIA = 2 * Math.PI * 52;

/**
 * Actualiza el contador de caracteres debajo del textarea en tiempo real.
 *
 * QUÉ PASARÍA SI NO EXISTIERA: el usuario no tendría ninguna referencia
 * visual de cuánto texto pegó, especialmente relevante porque el backend
 * trunca a MAX_INPUT_CHARS — sin el contador, no sabría si su texto va a
 * quedar cortado.
 */
function actualizarContador() {
    contadorCaracteres.textContent = areaEntrada.value.length;
}
areaEntrada.addEventListener('input', actualizarContador);

/**
 * Muestra un mensaje de error visible arriba de los botones.
 *
 * Centralizar esto en una función evita que cada bloque catch tenga que
 * repetir la lógica de mostrar/ocultar el elemento de error, y garantiza
 * que el mensaje siempre se muestre de la misma forma en toda la app.
 */
function mostrarError(texto) {
    mensajeError.textContent = texto;
    mensajeError.hidden = false;
}

function ocultarError() {
    mensajeError.hidden = true;
}

/**
 * Cambia el estado visual de un botón entre "normal" y "cargando",
 * deshabilitándolo mientras dura la petición para evitar que el usuario
 * dispare la misma acción dos veces en simultáneo (lo cual generaría dos
 * peticiones concurrentes y resultados que se pisan entre sí).
 *
 * QUÉ PASARÍA SI NO DESHABILITÁRAMOS EL BOTÓN: un click doble accidental
 * mandaría dos peticiones a Hugging Face (gastando cuota de más) y las
 * respuestas podrían llegar desordenadas, mostrando un resultado viejo
 * pisando a uno más nuevo.
 */
function ponerCargando(boton, cargando) {
    boton.disabled = cargando;
    boton.querySelector('.btn-texto').hidden = cargando;
    boton.querySelector('.btn-cargando').hidden = !cargando;
}

/**
 * Envoltorio único para hablar con detector.php. Todas las llamadas al
 * backend pasan por acá, así el manejo de errores de red y de formato de
 * respuesta está en un solo lugar en vez de repetirse en cada acción.
 *
 * async/await en vez de .then() encadenado: hace que el código se lea de
 * arriba a abajo como pasos secuenciales (mandar → esperar → usar el
 * resultado), en vez de anidar funciones de callback.
 *
 * QUÉ PASARÍA SI NO VALIDÁRAMOS response.ok NI el campo "ok" del JSON:
 * un error 500 de PHP (por ejemplo, un token vencido) podría devolver una
 * página HTML de error de Apache en vez de JSON, y response.json() fallaría
 * con una excepción confusa en vez de un mensaje claro para el usuario.
 */
async function llamarBackend(accion, texto) {
    const respuesta = await fetch('detector.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: accion, text: texto }),
    });

    let datos;
    try {
        datos = await respuesta.json();
    } catch (_error) {
        throw new Error('El servidor no devolvió un JSON válido (revisá los logs de PHP).');
    }

    if (!respuesta.ok || !datos.ok) {
        throw new Error(datos.error || 'Error desconocido del servidor.');
    }

    return datos;
}

/**
 * Anima un número contando desde su valor actual hasta "valorFinal" en
 * "duracionMs" milisegundos, usando requestAnimationFrame para que la
 * animación sea fluida y respete el framerate del navegador.
 *
 * QUÉ PASARÍA SI SOLO ASIGNÁRAMOS EL NÚMERO DIRECTO (sin animar): el
 * resultado aparecería de golpe, sin darle al usuario una sensación de
 * "cálculo en progreso" — es un detalle menor, pero es la clase de detalle
 * que separa una demo prolija de una tosca.
 */
function animarNumero(elemento, valorFinal, duracionMs = 800) {
    const valorInicial = 0;
    const inicio = performance.now();

    function paso(ahora) {
        const progreso = Math.min(1, (ahora - inicio) / duracionMs);
        // easing simple (ease-out cúbico) para que la animación desacelere
        // al final en vez de tener una velocidad constante y "robótica".
        const progresoSuavizado = 1 - Math.pow(1 - progreso, 3);
        const valorActual = Math.round(valorInicial + (valorFinal - valorInicial) * progresoSuavizado);
        elemento.textContent = valorActual;

        if (progreso < 1) {
            requestAnimationFrame(paso);
        }
    }
    requestAnimationFrame(paso);
}

/**
 * Pinta todos los resultados de detección en el panel derecho: el anillo
 * circular grande (humano vs IA) y las tres barras de desglose.
 *
 * Recibe "scores" con la forma { human, chatgpt, claude, gemini } que
 * devuelve detector.php, ya en porcentajes (0-100).
 */
function mostrarResultadoDeteccion(scores, aviso) {
    estadoVacio.hidden = true;
    resultadoDeteccion.hidden = false;

    const probabilidadIA = 100 - scores.human;
    const esMayormenteIA = probabilidadIA >= 50;

    // El anillo siempre muestra "probabilidad de ser IA" como métrica
    // principal, porque es el dato calibrado — mostrar a veces "% humano"
    // y a veces "% IA" según cuál sea mayor confundiría al usuario sobre
    // qué significa el número en cada caso.
    animarNumero(medidorValor, probabilidadIA);
    medidorEtiqueta.textContent = 'probabilidad de ser generado por IA';

    // stroke-dashoffset funciona "al revés": un offset de 0 dibuja el
    // círculo completo, y un offset igual a la circunferencia lo deja
    // vacío. Por eso restamos la fracción del porcentaje a la circunferencia
    // total en vez de usarla directamente.
    const offset = CIRCUNFERENCIA * (1 - probabilidadIA / 100);
    medidorProgreso.style.strokeDashoffset = offset;
    medidorProgreso.style.stroke = esMayormenteIA ? 'var(--color-ia)' : 'var(--color-humano)';

    // Barras de desglose (Claude/ChatGPT/Gemini)
    actualizarBarra('claude', scores.claude);
    actualizarBarra('chatgpt', scores.chatgpt);
    actualizarBarra('gemini', scores.gemini);

    avisoDeteccion.textContent = aviso || '';
}

/**
 * Actualiza una barra individual de desglose (ancho + número). Recibe el
 * nombre del modelo en minúsculas ("claude", "chatgpt" o "gemini") y lo
 * usa para construir los IDs de los elementos correspondientes — así se
 * evita escribir tres funciones casi idénticas, una por modelo.
 */
function actualizarBarra(nombreModelo, valor) {
    document.getElementById('barra-' + nombreModelo).style.width = valor + '%';
    document.getElementById('valor-' + nombreModelo).textContent = valor + '%';
}

/**
 * Maneja el click en "Analizar texto": valida que haya contenido, llama
 * al backend con action=detect, y pinta el resultado (o el error).
 */
async function manejarAnalizar() {
    ocultarError();
    const texto = areaEntrada.value.trim();

    if (texto === '') {
        mostrarError('Pegá un texto antes de analizar.');
        return;
    }

    ponerCargando(btnAnalizar, true);
    try {
        const datos = await llamarBackend('detect', texto);
        mostrarResultadoDeteccion(datos.scores, datos.aviso);
    } catch (error) {
        mostrarError('No se pudo analizar el texto: ' + error.message);
    } finally {
        ponerCargando(btnAnalizar, false);
    }
}

/**
 * Maneja el click en "Humanizar contenido": llama al backend con
 * action=humanize y muestra el texto reescrito en su propia caja,
 * debajo (o en paralelo con) el resultado de detección.
 */
async function manejarHumanizar() {
    ocultarError();
    const texto = areaEntrada.value.trim();

    if (texto === '') {
        mostrarError('Pegá un texto antes de humanizar.');
        return;
    }

    ponerCargando(btnHumanizar, true);
    try {
        const datos = await llamarBackend('humanize', texto);
        estadoVacio.hidden = true;
        resultadoHumanizado.hidden = false;
        textoHumanizado.value = datos.text;
    } catch (error) {
        mostrarError('No se pudo humanizar el texto: ' + error.message);
    } finally {
        ponerCargando(btnHumanizar, false);
    }
}

/**
 * Copia el texto humanizado al portapapeles usando la API moderna
 * navigator.clipboard, con un pequeño cambio de texto en el botón como
 * confirmación visual.
 *
 * QUÉ PASARÍA SI USÁRAMOS document.execCommand('copy') EN VEZ DE ESTO:
 * esa API está deprecada en navegadores modernos y puede dejar de
 * funcionar sin aviso en versiones futuras — clipboard.writeText es el
 * reemplazo oficial y estándar.
 */
async function copiarTextoHumanizado() {
    try {
        await navigator.clipboard.writeText(textoHumanizado.value);
        const textoOriginalBoton = btnCopiar.textContent;
        btnCopiar.textContent = 'Copiado ✓';
        setTimeout(() => { btnCopiar.textContent = textoOriginalBoton; }, 1500);
    } catch (_error) {
        mostrarError('No se pudo copiar automáticamente. Seleccioná el texto y copiá manualmente.');
    }
}

// --- Conexión de eventos -------------------------------------------------
// Todo el "cableado" de la app vive acá abajo, en un solo lugar, para que
// sea fácil ver de un vistazo qué botón dispara qué función sin tener que
// buscar addEventListener disperso por el resto del archivo.
btnAnalizar.addEventListener('click', manejarAnalizar);
btnHumanizar.addEventListener('click', manejarHumanizar);
btnCopiar.addEventListener('click', copiarTextoHumanizado);

actualizarContador();
