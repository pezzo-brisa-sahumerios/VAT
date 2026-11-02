<?php
/* =============================================================================
 * VAT · Tokenizador — normalización, tokens, n-gramas y oraciones
 * -----------------------------------------------------------------------------
 * Réplica exacta de entrenamiento/features.py y assets/js/motor-vat.js.
 * Cualquier diferencia aquí desalinearía el texto con los pesos aprendidos.
 * Si esta clase faltara, el clasificador no tendría unidades que puntuar.
 * ============================================================================= */

declare(strict_types=1);

final class Tokenizador
{
    /* (*UCP) hace que \s reconozca espacios Unicode (como el espacio duro U+00A0),
     * igual que Python y JavaScript. Sin (*UCP), PHP trataría un espacio duro
     * como signo y generaría n-gramas que no existen en el modelo. */
    public const RE_TOKEN = '/(*UCP)[\p{L}\p{N}]+|[^\s\p{L}\p{N}]/u';
    public const RE_PALABRA = '/[\p{L}\p{N}]+/u';
    public const RE_ORACION = '/(*UCP)(?<=[.!?…])\s+|\n+/u';

    /**
     * Unicode NFC + saltos de línea unificados. Si la extensión intl no está
     * instalada se omite NFC (el 99,9% de los textos ya viene en NFC); sin esta
     * comprobación, la llamada a Normalizer provocaría un error fatal.
     */
    public static function normalizar(string $texto): string
    {
        if (class_exists('Normalizer')) {
            $n = Normalizer::normalize($texto, Normalizer::FORM_C);
            if ($n !== false) {
                $texto = $n;
            }
        }
        return str_replace(["\r\n", "\r"], "\n", $texto);
    }

    /** Minúsculas, "¶" por salto de línea y "<n>" por número puro (ver motor JS). */
    public static function tokenizar(string $texto): array
    {
        $base = preg_replace('/\n+/', ' ¶ ', mb_strtolower($texto, 'UTF-8'));
        preg_match_all(self::RE_TOKEN, (string) $base, $m);
        $salida = [];
        foreach ($m[0] as $t) {
            // ctype_digit sólo acepta 0-9 ASCII, igual que la regex ^[0-9]+$ de JS/Python.
            $salida[] = ctype_digit($t) ? '<n>' : $t;
        }
        return $salida;
    }

    /** Unigramas + bigramas. Sin bigramas se pierden colocaciones típicas de IA ("cabe destacar"). */
    public static function ngramas(array $tokens): array
    {
        $salida = $tokens;
        $n = count($tokens);
        for ($i = 0; $i < $n - 1; $i++) {
            $salida[] = $tokens[$i] . ' ' . $tokens[$i + 1];
        }
        return $salida;
    }

    /** Palabras (letras/números) en minúsculas: base de toda la estilometría. */
    public static function palabras(string $texto): array
    {
        preg_match_all(self::RE_PALABRA, mb_strtolower($texto, 'UTF-8'), $m);
        return $m[0];
    }

    /** Oraciones no vacías. Sin esto no habría burstiness ni citas de evidencia. */
    public static function oraciones(string $texto): array
    {
        $partes = preg_split(self::RE_ORACION, $texto) ?: [];
        $salida = [];
        foreach ($partes as $p) {
            $p = trim($p);
            if ($p !== '' && preg_match('/[\p{L}\p{N}]/u', $p)) {
                $salida[] = $p;
            }
        }
        return $salida;
    }
}
