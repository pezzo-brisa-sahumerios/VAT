<?php
/* =============================================================================
 * VAT · Estilometría — 24 rasgos de estilo medidos sobre el texto
 * -----------------------------------------------------------------------------
 * Mide ritmo (burstiness), diversidad léxica, puntuación, formato y uso de
 * pronombres. Estos rasgos detectan IA aun cuando el vocabulario fue
 * parafraseado. El orden de salida DEBE coincidir con modelo.densos.nombres.
 * Si esta clase faltara, el modelo perdería ~1 punto de AUC y sería más
 * vulnerable a la paráfrasis.
 * ============================================================================= */

declare(strict_types=1);

final class Estilometria
{
    /* Listas idénticas a features.py: cambiarlas sin reentrenar sesga los rasgos. */
    private const STOP = 'de la que el en y a los del se las por un para con no una su al lo como más pero sus le ya o este sí porque esta entre cuando muy sin sobre también me hasta hay donde quien desde todo nos durante todos uno les ni contra otros ese eso ante ellos e esto mí antes algunos qué unos yo otro otras otra él tanto esa estos mucho quienes nada muchos cual poco ella estar estas algunas algo nosotros es son fue ser ha han era the of and to a in is that it was for on are as with his they be at one have this from by or had not but what all were when we there can an your which their if do will each about how up out them then she many some so these would other into has more her two like him see time could no make than first been its who now people my made over did down only way find use may long very after';
    private const P1 = 'yo me mi mis conmigo mío mía i my mine myself';
    private const P2 = 'tú tu tus te ti usted ustedes vos you your yours yourself';

    /** @var array<string,int>|null Conjuntos como claves de array para búsqueda O(1). */
    private static ?array $stop = null;
    private static ?array $p1 = null;
    private static ?array $p2 = null;

    /** Inicializa los conjuntos una sola vez (sin esto se reconstruirían en cada palabra). */
    private static function conjuntos(): void
    {
        if (self::$stop === null) {
            self::$stop = array_flip(preg_split('/\s+/', self::STOP));
            self::$p1 = array_flip(explode(' ', self::P1));
            self::$p2 = array_flip(explode(' ', self::P2));
        }
    }

    public static function esFuncional(string $w): bool
    {
        self::conjuntos();
        return isset(self::$stop[$w]);
    }

    /**
     * Calcula los rasgos. Devuelve ['valores'=>[...], 'palabras'=>[...], 'oraciones'=>[...], 'lineas'=>[...]].
     * max($n,1) evita "Division by zero" (DivisionByZeroError en PHP 8) con textos sin palabras.
     */
    public static function calcular(string $texto): array
    {
        self::conjuntos();
        $palabras = Tokenizador::palabras($texto);
        $n = max(count($palabras), 1);
        $oraciones = Tokenizador::oraciones($texto);

        $largos = [];
        foreach ($oraciones as $o) {
            $largos[] = count(Tokenizador::palabras($o));
        }
        if (!$largos) {
            $largos = [$n]; // Texto sin cortes: se trata como una sola oración.
        }
        $media = array_sum($largos) / count($largos);
        $var = 0.0;
        foreach ($largos as $x) {
            $var += ($x - $media) ** 2;
        }
        $desv = sqrt($var / count($largos)); // Desviación poblacional (igual que Python/JS).

        // MATTR en ventanas de 50 palabras con paso 10 (independiente de la longitud del texto).
        if (count($palabras) < 50) {
            $mattr = count(array_unique($palabras)) / $n;
        } else {
            $suma = 0.0;
            $k = 0;
            for ($i = 0; $i < count($palabras) - 49; $i += 10) {
                $suma += count(array_unique(array_slice($palabras, $i, 50))) / 50;
                $k++;
            }
            $mattr = $suma / $k;
        }

        $lineas = array_values(array_filter(explode("\n", $texto), static fn($l) => trim($l) !== ''));
        $nl = max(count($lineas), 1);

        $primeras = [];
        foreach ($oraciones as $o) {
            $pw = Tokenizador::palabras($o);
            $primeras[] = $pw[0] ?? '';
        }

        // Conteo de emojis recorriendo puntos de código reales (mb_str_split respeta UTF-8).
        $emojis = 0;
        foreach (mb_str_split($texto, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if (($cp >= 0x1F300 && $cp <= 0x1FAFF) || ($cp >= 0x2600 && $cp <= 0x27BF)) {
                $emojis++;
            }
        }

        $largas = 0;
        $letras = 0;
        $digitos = 0;
        $stop = 0;
        $p1 = 0;
        $p2 = 0;
        foreach ($palabras as $w) {
            $len = mb_strlen($w, 'UTF-8');
            $letras += $len;
            if ($len >= 7) $largas++;
            if (preg_match('/[0-9]/', $w)) $digitos++;
            if (isset(self::$stop[$w])) $stop++;
            if (isset(self::$p1[$w])) $p1++;
            if (isset(self::$p2[$w])) $p2++;
        }
        $vinetas = 0;
        $md = 0;
        foreach ($lineas as $l) {
            if (preg_match('/^\s*([-*•]|\d+[.)])\s/u', $l)) $vinetas++;
            if (preg_match('/^\s*#{1,6}\s|\*\*/u', $l)) $md++;
        }
        $c = static fn(string $s) => substr_count($texto, $s); // Conteo no solapado, como str.count de Python.

        $valores = [
            'sl_mean' => min($media, 80),
            'sl_cv' => $media ? $desv / $media : 0,
            'mattr' => $mattr,
            'wlen' => $letras / $n,
            'long' => $largas / $n,
            'comma' => $c(',') / $n,
            'semi' => $c(';') / $n,
            'colon' => $c(':') / $n,
            'dash' => ($c('—') + $c('–')) / $n,
            'paren' => $c('(') / $n,
            'quote' => ($c('"') + $c('“') + $c('”') + $c('«') + $c('»')) / $n,
            'quest' => $c('?') / $n,
            'excl' => $c('!') / $n,
            'ellip' => ($c('…') + $c('...')) / $n,
            'digit' => $digitos / $n,
            'bullet' => $vinetas / $nl,
            'md' => $md / $nl,
            'para' => count($lineas) / $n * 100,
            'stop' => $stop / $n,
            'p1' => $p1 / $n,
            'p2' => $p2 / $n,
            'contr' => ($c("'") + $c('’')) / $n,
            'startrep' => count($primeras) >= 2 ? 1 - count(array_unique($primeras)) / count($primeras) : 0,
            'emoji' => $emojis / $n,
        ];
        return ['valores' => $valores, 'palabras' => $palabras, 'oraciones' => $oraciones, 'lineas' => $lineas];
    }
}
