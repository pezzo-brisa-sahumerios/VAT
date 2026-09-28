<?php
/**
 * prompts.php — Todos los textos que se le mandan a la IA, en un solo lugar.
 *
 * POR QUÉ ESTÁ SEPARADO DE detector.php: si el prompt de humanización
 * estuviera escrito adentro de la lógica del backend, cualquier ajuste de
 * redacción (agregar una regla, cambiar el tono) obligaría a tocar código
 * PHP con lógica de red, cURL y manejo de errores — más difícil de editar
 * a mano y más fácil de romper por accidente.
 *
 * Acá adentro solo hay texto. Podés cambiar cualquier valor de este archivo
 * sin tocar detector.php, y el sistema sigue funcionando igual.
 *
 * QUÉ PASARÍA SI ESTE ARCHIVO NO EXISTIERA: detector.php no tendría
 * instrucciones que mandarle al modelo de humanización, y esa acción
 * fallaría (o peor, mandaría un prompt vacío y el modelo devolvería
 * cualquier cosa sin criterio).
 *
 * Esta función devuelve un array asociativo en vez de definir constantes
 * sueltas, para que detector.php pueda pedir "dame el prompt de humanizar"
 * con una sola línea (require + acceso a la clave), en vez de tener que
 * conocer de memoria el nombre exacto de cada constante.
 */

return [

    /**
     * Prompt de sistema para la acción "humanize".
     * Le dice al LLM CÓMO comportarse antes de mostrarle el texto del
     * usuario. Es el prompt más importante del sistema: de acá depende
     * casi toda la calidad de la humanización.
     *
     * Para editar el estilo de humanización, modificá SOLO este texto.
     */
    'humanizar_sistema' =>
        'Sos un reescritor estilístico. Tu único trabajo es reescribir el texto del usuario '
        . 'para que suene a prosa humana espontánea, manteniendo el MISMO idioma (español) y el MISMO significado. '
        . 'Alterná el largo de las oraciones: mezclá oraciones cortas con otras más largas, evitá longitudes homogéneas. '
        . 'Eliminá conectores típicos de IA como "además,", "por otro lado,", "en conclusión,", '
        . '"es importante destacar", "en este sentido", listas de tres elementos simétricos, y cierres tipo "en resumen". '
        . 'Variá el vocabulario: no repitas la misma palabra clave más de dos veces si hay sinónimos naturales disponibles. '
        . 'No agregues títulos, metadatos, comillas envolventes ni comentarios sobre lo que hiciste. '
        . 'No expliques el proceso ni digas "aquí tenés el texto reescrito". '
        . 'Devolvé SOLO el texto reescrito, nada más.',

    /**
     * Instrucción que se antepone al texto del usuario en la acción
     * "humanize". Separada del prompt de sistema porque cumple un rol
     * distinto: el prompt de sistema define el COMPORTAMIENTO general,
     * esta línea define la TAREA puntual de este mensaje.
     */
    'humanizar_instruccion_usuario' => 'Reescribí el siguiente texto:',

    /**
     * Texto que se muestra al usuario final junto a los resultados de
     * detección, explicando qué parte del resultado es un dato calibrado
     * y qué parte es una estimación. Vive acá (no hardcodeado en detector.php)
     * para que se pueda ajustar la redacción sin tocar lógica de negocio.
     */
    'aviso_deteccion' =>
        'El porcentaje humano/IA sale de un clasificador entrenado con datos reales. '
        . 'La división entre ChatGPT, Claude y Gemini es una estimación: hasta que el '
        . 'modelo propio (entrenado con el dataset de VAT) esté conectado, se reparte '
        . 'en partes iguales porque no hay una fuente calibrada para distinguir entre los tres.',

];
