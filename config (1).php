<?php
/* =============================================================================
 * VAT · Configuración central del backend PHP
 * -----------------------------------------------------------------------------
 * Reúne en un solo lugar las constantes que usan el endpoint y el motor.
 * Si este archivo no existiera, api/analizar.php fallaría con un error fatal
 * "Failed opening required" y el frontend pasaría automáticamente al motor
 * JavaScript local (funciona igual, pero sin ejecución en el servidor).
 * ============================================================================= */

declare(strict_types=1); // Tipado estricto: un tipo incorrecto lanza TypeError en vez de convertirse en silencio.

/* Ruta absoluta al modelo calibrado. Se usa __DIR__ para que funcione sin
 * importar desde qué carpeta se invoque el script. Sin esta constante el
 * motor no sabría dónde están los pesos y no podría puntuar nada. */
define('VAT_RUTA_MODELO', dirname(__DIR__) . '/modelo/vat-modelo.json');

/* Límite de caracteres por análisis. Protege al servidor de textos enormes
 * (ataques de denegación de servicio o pegados accidentales de libros).
 * Sin límite, un POST de 50 MB podría agotar memoria/CPU del hosting. */
define('VAT_MAX_CARACTERES', 60000);

/* Límite de peticiones por IP y minuto (anti-abuso básico, sin base de datos).
 * Sin él, un script podría saturar el servidor con miles de análisis. */
define('VAT_LIMITE_POR_MINUTO', 30);

/* Protocolo forense que rige el análisis. No es un "prompt" para una IA
 * externa (VAT no usa APIs): es la especificación que el motor implementa y que
 * se devuelve en la respuesta para transparencia. Si se quitara, el sistema
 * funcionaría igual, pero el informe perdería la declaración de método. */
define('VAT_PROTOCOLO_FORENSE', 'Actúa como analizador lingüístico forense: no adivines; '
    . 'mide n-gramas y rasgos estilométricos, compáralos con distribuciones aprendidas de textos reales, '
    . 'calibra la probabilidad, acusa de IA sólo por encima del umbral de ≤1% de falsos positivos '
    . 'y justifica cada conclusión con evidencia citada.');
