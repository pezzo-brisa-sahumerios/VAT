<?php
/* =============================================================================
 * VAT · ClasificadorVAT — matemática del modelo (puntaje, calibración, familias)
 * -----------------------------------------------------------------------------
 * Regresión logística binaria (humano vs IA) + multinomial (7 familias) sobre
 * TF sublineal normalizado L2 + 24 rasgos estandarizados. Al ser lineal, cada
 * aporte es exacto y explicable. Si esta clase faltara no existiría
 * probabilidad alguna: el sistema no podría emitir veredictos.
 * ============================================================================= */

declare(strict_types=1);

final class ClasificadorVAT
{
    /* Igual que en motor-vat.js: centra la escala de puntaje por oración. */
    public const DESPLAZAMIENTO_ORACION = 1.0;

    /**
     * Calcula z binario, z por familia y aportes individuales.
     * Sin el recorte a ±5 de los rasgos, un único valor anómalo (p. ej. un
     * texto lleno de emojis) podría decidir solo todo el veredicto.
     */
    public static function puntuar(string $texto, array $M): array
    {
        $F = $M['F'];
        $E = $M['escala']; // Los pesos se guardan como enteros ×1000 para reducir el tamaño del modelo.
        $tokens = Tokenizador::tokenizar($texto);

        $cuentas = [];
        foreach (Tokenizador::ngramas($tokens) as $g) {
            if (isset($M['indice'][$g])) {
                $i = $M['indice'][$g];
                $cuentas[$i] = ($cuentas[$i] ?? 0) + 1;
            }
        }
        $norma = 0.0;
        foreach ($cuentas as $c) {
            $v = 1 + log($c);
            $norma += $v * $v;
        }
        $norma = sqrt($norma) ?: 1.0; // Sin n-gramas conocidos: evita dividir por cero.

        $z = (float) $M['sesgo'];
        $zf = array_map('floatval', $M['sesgo_fam']);
        $aportesTerm = [];
        foreach ($cuentas as $i => $c) {
            $v = (1 + log($c)) / $norma;
            $a = $v * $M['wb'][$i] / $E;
            $z += $a;
            for ($k = 0; $k < $F; $k++) {
                $zf[$k] += $v * $M['wf'][$i * $F + $k] / $E;
            }
            $aportesTerm[] = ['termino' => $M['vocab'][$i], 'aporte' => $a, 'veces' => $c];
        }

        $D = $M['densos'];
        $dens = Estilometria::calcular($texto);
        $aportesRasgo = [];
        foreach ($D['nombres'] as $j => $nombre) {
            $x = $dens['valores'][$nombre];
            $xs = max(-5, min(5, ($x - $D['media'][$j]) / $D['desv'][$j]));
            $a = $xs * $D['wb'][$j] / $E;
            $z += $a;
            for ($k = 0; $k < $F; $k++) {
                $zf[$k] += $xs * $D['wf'][$j * $F + $k] / $E;
            }
            $aportesRasgo[] = ['id' => $nombre, 'valor' => $x, 'z' => $xs, 'aporte' => $a,
                'media_humano' => $D['media_humano'][$j], 'media_ia' => $D['media_ia'][$j]];
        }
        return ['z' => $z, 'zf' => $zf, 'aportesTerm' => $aportesTerm, 'aportesRasgo' => $aportesRasgo,
            'dens' => $dens, 'tokens' => $tokens, 'conocidos' => count($cuentas)];
    }

    /** Escalado de Platt: puntaje bruto → probabilidad calibrada con datos de validación reales. */
    public static function calibrar(float $z, array $platt): float
    {
        return 1 / (1 + exp(-($platt['a'] * $z + $platt['b'])));
    }

    /** Softmax estable (resta el máximo): sin esto exp() desborda a INF con puntajes grandes. */
    public static function softmax(array $v): array
    {
        $m = max($v);
        $e = array_map(static fn($x) => exp($x - $m), $v);
        $s = array_sum($e);
        return array_map(static fn($x) => $x / $s, $e);
    }

    /** Puntaje local por oración (dónde está la evidencia). Ver explicación en motor-vat.js. */
    public static function oraciones(array $oraciones, array $M): array
    {
        $salida = [];
        foreach ($oraciones as $idx => $texto) {
            $gs = Tokenizador::ngramas(Tokenizador::tokenizar($texto));
            $suma = 0.0;
            $top = [];
            foreach ($gs as $g) {
                if (isset($M['indice'][$g])) {
                    $w = $M['wb'][$M['indice'][$g]] / $M['escala'];
                    $suma += $w;
                    $top[] = [$g, $w];
                }
            }
            $p = tanh($suma / sqrt(count($gs) + 1) + self::DESPLAZAMIENTO_ORACION);
            usort($top, static fn($a, $b) => $b[1] <=> $a[1]);
            $claves = [];
            foreach ($top as $t) {
                if ($t[1] > 0 && count($claves) < 3) $claves[] = $t[0];
            }
            $salida[] = ['indice' => $idx, 'texto' => $texto, 'puntaje' => $p,
                'nivel' => $p >= 0.35 ? 'ia' : ($p <= -0.35 ? 'humano' : 'neutro'), 'claves' => $claves];
        }
        return $salida;
    }
}
