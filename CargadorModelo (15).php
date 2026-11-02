<?php
/* =============================================================================
 * VAT · CargadorModelo — lee y prepara el modelo forense calibrado
 * -----------------------------------------------------------------------------
 * El modelo (vat-modelo.json, ~1,2 MB) contiene vocabulario, pesos, medias de
 * rasgos, parámetros de calibración y umbrales. Esta clase lo carga una sola
 * vez por proceso y construye un índice término→posición.
 * Si esta clase no existiera, ninguna otra pieza tendría acceso a los pesos y
 * el endpoint respondería con error 500.
 * ============================================================================= */

declare(strict_types=1);

final class CargadorModelo
{
    /** @var array|null Caché estática: evita releer y decodificar 1,2 MB en cada llamada del mismo proceso. */
    private static ?array $modelo = null;

    /**
     * Devuelve el modelo listo para usar (con 'indice' = array asociativo término→posición).
     * Si el archivo falta o está corrupto lanza RuntimeException; sin esa
     * validación, json_decode devolvería null y más adelante aparecerían
     * avisos "Trying to access array offset on null" con resultados basura.
     */
    public static function obtener(string $ruta): array
    {
        if (self::$modelo !== null) {
            return self::$modelo; // Ya cargado en este proceso: respuesta inmediata.
        }

        // APCu (si el hosting lo tiene) guarda el modelo en memoria compartida entre peticiones.
        // Sin APCu todo funciona igual, sólo se decodifica el JSON en cada petición (~15 ms).
        $claveCache = 'vat_modelo_' . (is_file($ruta) ? filemtime($ruta) : 0);
        if (function_exists('apcu_fetch')) {
            $enCache = apcu_fetch($claveCache, $ok);
            if ($ok && is_array($enCache)) {
                return self::$modelo = $enCache;
            }
        }

        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new RuntimeException('No se encontró el modelo forense en ' . basename($ruta));
        }
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($datos) || !isset($datos['vocab'], $datos['wb'], $datos['densos'], $datos['platt'])) {
            throw new RuntimeException('El archivo del modelo está dañado o incompleto.');
        }

        // array_flip convierte [0=>'de',1=>'la'] en ['de'=>0,'la'=>1]: búsqueda O(1) por término.
        // Sin este índice habría que usar array_search (O(n)) por cada n-grama → análisis 10.000 veces más lento.
        $datos['indice'] = array_flip($datos['vocab']);
        $datos['F'] = count($datos['familias']);

        if (function_exists('apcu_store')) {
            apcu_store($claveCache, $datos, 3600);
        }
        return self::$modelo = $datos;
    }
}
