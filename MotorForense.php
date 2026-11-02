<?php
/* =============================================================================
 * VAT · MotorForense — orquestador del protocolo de análisis
 * -----------------------------------------------------------------------------
 * Une Tokenizador → Estilometria → ClasificadorVAT → Explicador y arma la
 * respuesta JSON (mismo esquema que analizar() de motor-vat.js, para que la
 * interfaz no distinga qué motor respondió).
 * Si esta clase faltara, el endpoint no tendría a quién delegar el análisis.
 * ============================================================================= */

declare(strict_types=1);

require_once __DIR__ . '/CargadorModelo.php';
require_once __DIR__ . '/Tokenizador.php';
require_once __DIR__ . '/Estilometria.php';
require_once __DIR__ . '/ClasificadorVAT.php';
require_once __DIR__ . '/Explicador.php';

final class MotorForense
{
    private array $M;

    /** Recibe la ruta del modelo; si falla la carga, la excepción sube al endpoint (que responde 500 con mensaje claro). */
    public function __construct(string $rutaModelo)
    {
        $this->M = CargadorModelo::obtener($rutaModelo);
    }

    /** Análisis completo. Devuelve un array listo para json_encode. */
    public function analizar(string $original): array
    {
        $t0 = microtime(true);
        $M = $this->M;
        $texto = trim(Tokenizador::normalizar($original));
        if ($texto === '') {
            return ['ok' => false, 'error' => 'El texto está vacío.'];
        }
        if (mb_strlen($texto, 'UTF-8') > VAT_MAX_CARACTERES) {
            return ['ok' => false, 'error' => 'Texto demasiado largo (máximo 60.000 caracteres).'];
        }

        $r = ClasificadorVAT::puntuar($texto, $M);
        $dens = $r['dens'];
        $U = $M['umbrales'];
        $nPal = count($dens['palabras']);
        $p = ClasificadorVAT::calibrar($r['z'], $M['platt']);
        $idioma = Explicador::idioma($dens['palabras']);
        $famProb = ClasificadorVAT::softmax($r['zf']);
        $oraciones = ClasificadorVAT::oraciones($dens['oraciones'], $M);
        $f = [Explicador::class, 'fmt'];

        /* Veredicto conservador: "IA" sólo sobre el umbral de ≤1% de falsos positivos.
         * Usar 50% como corte acusaría injustamente a ~6 de cada 100 textos humanos. */
        if ($nPal < $U['min_palabras']) {
            $codigo = 'insuficiente';
            $titulo = 'Texto demasiado corto';
            $detalle = 'Con menos de ' . $U['min_palabras'] . ' palabras ningún detector es fiable. Pegá al menos 1–2 párrafos (ideal: 150+ palabras).';
        } elseif ($p >= $U['ia']) {
            $codigo = 'ia';
            $titulo = 'Generado por IA';
            $detalle = 'La probabilidad calibrada supera el umbral estricto (' . $f($U['ia'] * 100, 1) . '%) fijado para no acusar a textos humanos.';
        } elseif ($p <= $U['humano']) {
            $codigo = 'humano';
            $titulo = 'Escrito por un humano';
            $detalle = 'El texto no presenta el patrón estadístico de los modelos de lenguaje.';
        } else {
            $codigo = 'no_concluyente';
            $titulo = 'No concluyente';
            $detalle = 'Hay señales mezcladas: puede ser texto humano muy formal, IA editada/parafraseada o un texto mixto. VAT prefiere no acusar sin pruebas suficientes.';
        }

        // Confianza = distancia al umbral × factor de longitud (más palabras, más evidencia).
        $distancia = $codigo === 'ia' ? ($p - $U['ia']) / (1 - $U['ia']) : ($codigo === 'humano' ? ($U['humano'] - $p) / $U['humano'] : 0);
        $factorLongitud = min(1, $nPal / 250);
        $confianza = $codigo === 'insuficiente' ? 0.1 : max(0.05, min(0.99, (0.55 + 0.45 * $distancia) * (0.5 + 0.5 * $factorLongitud)));

        // Texto mixto: ≥25% de oraciones con señal IA y ≥25% con señal humana.
        $nIA = count(array_filter($oraciones, static fn($o) => $o['nivel'] === 'ia'));
        $nHum = count(array_filter($oraciones, static fn($o) => $o['nivel'] === 'humano'));
        $nOr = count($oraciones);
        $mixto = $nOr >= 6 && $nIA / $nOr >= 0.25 && $nHum / $nOr >= 0.25;

        // Atribución de familia (ordenada por probabilidad).
        $familias = [];
        foreach ($M['familias'] as $k => $id) {
            $familias[] = ['id' => $id, 'nombre' => Explicador::FAMILIAS[$id] ?? $id, 'prob' => $famProb[$k]];
        }
        usort($familias, static fn($a, $b) => $b['prob'] <=> $a['prob']);
        $atribDisponible = $codigo === 'ia' || ($codigo === 'no_concluyente' && $p >= 0.5);
        $confiable = $familias[0]['prob'] >= 0.5 && $familias[0]['prob'] - $familias[1]['prob'] >= 0.2;

        // Aportes reales del modelo (explicación exacta, no inventada).
        $terms = $r['aportesTerm'];
        usort($terms, static fn($a, $b) => $b['aporte'] <=> $a['aporte']);
        $map = static fn($t) => ['termino' => str_replace('¶', '↵', $t['termino']), 'peso' => $t['aporte'], 'veces' => $t['veces']];
        $ngIA = array_map($map, array_slice(array_values(array_filter($terms, static fn($t) => $t['aporte'] > 0)), 0, 12));
        $ngHum = array_map($map, array_slice(array_reverse(array_values(array_filter($terms, static fn($t) => $t['aporte'] < 0))), 0, 12));

        $rasgos = [];
        foreach ($r['aportesRasgo'] as $a) {
            $meta = Explicador::RASGOS[$a['id']] ?? [$a['id'], ''];
            $rasgos[] = ['id' => $a['id'], 'nombre' => $meta[0], 'unidad' => $meta[1], 'valor' => $a['valor'],
                'media_humano' => $a['media_humano'], 'media_ia' => $a['media_ia'], 'aporte' => $a['aporte'],
                'direccion' => $a['aporte'] >= 0 ? 'ia' : 'humano', 'escala' => max(0, min(1, 0.5 + $a['z'] / 6))];
        }
        usort($rasgos, static fn($a, $b) => abs($b['aporte']) <=> abs($a['aporte']));
        $sumaTerm = array_sum(array_column($r['aportesTerm'], 'aporte'));
        $sumaRasgo = array_sum(array_column($r['aportesRasgo'], 'aporte'));

        // Evidencias en lenguaje natural citando oraciones y valores medidos.
        $evidencias = [];
        $topOr = $oraciones;
        usort($topOr, static fn($a, $b) => $b['puntaje'] <=> $a['puntaje']);
        $k = 0;
        foreach ($topOr as $o) {
            if ($o['nivel'] !== 'ia' || $k >= 3) continue;
            $k++;
            $claves = $o['claves'] ? ' (n-gramas: ' . implode(', ', array_map(static fn($c) => '"' . str_replace('¶', '↵', $c) . '"', $o['claves'])) . ').' : '.';
            $evidencias[] = ['tipo' => 'ia', 'texto' => 'Oración ' . ($o['indice'] + 1) . ': «' . Explicador::recortar($o['texto'], 180) . '» concentra patrones típicos de IA' . $claves];
        }
        foreach (array_slice($rasgos, 0, 4) as $a) {
            $evidencias[] = ['tipo' => $a['direccion'], 'texto' => $a['nombre'] . ' = ' . $f($a['valor'], 3) . ' (media en textos humanos ' . $f($a['media_humano'], 3)
                . ' · en IA ' . $f($a['media_ia'], 3) . ') → empuja hacia ' . ($a['direccion'] === 'ia' ? 'IA' : 'HUMANO') . '.'];
        }
        $k = 0;
        foreach (array_reverse($topOr) as $o) {
            if ($o['nivel'] !== 'humano' || $k >= 2) continue;
            $k++;
            $evidencias[] = ['tipo' => 'humano', 'texto' => 'Oración ' . ($o['indice'] + 1) . ': «' . Explicador::recortar($o['texto'], 180) . '» tiene rasgos más propios de escritura humana.'];
        }

        $proceso = [
            ['paso' => 'Normalización', 'detalle' => 'Unicode NFC y saltos de línea unificados: ' . mb_strlen($texto, 'UTF-8') . ' caracteres, ' . $nPal . ' palabras, ' . count($dens['oraciones']) . ' oraciones, ' . count($dens['lineas']) . ' párrafos.'],
            ['paso' => 'Tokenización y n-gramas', 'detalle' => count($r['tokens']) . ' tokens → ' . $r['conocidos'] . ' n-gramas distintos reconocidos en el vocabulario forense (' . number_format(count($M['vocab']), 0, ',', '.') . ' entradas).'],
            ['paso' => 'Estilometría', 'detalle' => '24 rasgos medidos (ritmo, diversidad léxica, puntuación, formato, pronombres). Aporte total al puntaje: ' . $f($sumaRasgo, 2) . '.'],
            ['paso' => 'Modelo léxico', 'detalle' => 'Regresión logística entrenada con ~151.000 textos reales. Aporte de n-gramas: ' . $f($sumaTerm, 2) . '. Puntaje bruto z = ' . $f($r['z'], 3) . '.'],
            ['paso' => 'Calibración', 'detalle' => 'Escalado de Platt → probabilidad IA = ' . $f($p * 100, 1) . '%. Umbrales: IA ≥ ' . $f($U['ia'] * 100, 1) . '%, humano ≤ ' . $f($U['humano'] * 100, 1) . '%.'],
            ['paso' => 'Análisis por oración', 'detalle' => $nIA . ' oraciones con señal IA, ' . $nHum . ' con señal humana, ' . ($nOr - $nIA - $nHum) . ' neutras.' . ($mixto ? ' Distribución compatible con texto MIXTO.' : '')],
            ['paso' => 'Atribución', 'detalle' => $atribDisponible ? 'Clasificador multinomial de 7 familias: ' . $familias[0]['nombre'] . ' ' . $f($familias[0]['prob'] * 100, 1) . '%.' : 'No se atribuye modelo porque no hay evidencia suficiente de IA.'],
        ];

        return [
            'ok' => true, 'motor' => 'php', 'version' => $M['version'], 'protocolo' => VAT_PROTOCOLO_FORENSE,
            'idioma' => $idioma,
            'estadisticas' => ['palabras' => $nPal, 'caracteres' => mb_strlen($texto, 'UTF-8'), 'oraciones' => count($dens['oraciones']), 'parrafos' => count($dens['lineas'])],
            'probabilidad_ia' => $p, 'probabilidad_humano' => 1 - $p, 'puntaje_z' => $r['z'],
            'veredicto' => ['codigo' => $codigo, 'titulo' => $titulo, 'detalle' => $detalle, 'confianza' => $confianza],
            'mixto' => ['posible' => $mixto, 'detalle' => $mixto ? 'Se detectan bloques con señal IA y bloques con señal humana: el texto podría combinar partes escritas por una persona y partes generadas.' : ''],
            'atribucion' => ['disponible' => $atribDisponible, 'confiable' => $confiable, 'familias' => $familias,
                'nota' => $confiable ? 'Atribución con margen claro sobre la segunda opción.' : 'Atribución orientativa: la diferencia entre familias es pequeña. Modelos no vistos en el entrenamiento (p. ej. DeepSeek, Grok) se asignan a la familia más parecida.'],
            'rasgos' => $rasgos, 'ngramas' => ['ia' => $ngIA, 'humano' => $ngHum], 'oraciones' => $oraciones,
            'evidencias' => $evidencias, 'marcadores' => Explicador::marcadores($texto),
            'prompt_estimado' => Explicador::prompt($texto, $dens, $idioma), 'proceso' => $proceso,
            'calibracion' => ['umbral_ia' => $U['ia'], 'umbral_humano' => $U['humano'], 'metricas' => $M['metricas']],
            'tiempo_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }
}
