<?php
/* =============================================================================
 * VAT · Explicador — convierte números en un informe forense legible
 * -----------------------------------------------------------------------------
 * Contiene: nombres de rasgos, nombres de familias, marcadores discursivos
 * informativos, detección de idioma y reconstrucción del posible prompt.
 * Nada de esto modifica la probabilidad (que sale sólo del modelo entrenado).
 * Si esta clase faltara, el usuario recibiría un porcentaje sin explicación:
 * exactamente el "chamuyo" que VAT busca evitar.
 * ============================================================================= */

declare(strict_types=1);

final class Explicador
{
    /** Nombre y unidad de cada rasgo (mismo contenido que RASGOS en motor-vat.js). */
    /**
     * Palabras de estilo/relleno excluidas del "tema" del posible prompt (idéntica a GENERICOS en JS).
     * Sin esta lista el prompt estimado hablaría de "crucial, importante…" en vez del tema real.
     */
    public const GENERICOS = ['about', 'actual', 'actuales', 'additionally', 'además', 'aspecto', 'aspectos', 'conclusion', 'conclusión', 'could', 'crucial', 'cruciales', 'diversas', 'diversos', 'ejemplo', 'embargo', 'ensure', 'esencial', 'esenciales', 'essential', 'example', 'forma', 'fundamental', 'fundamentales', 'furthermore', 'general', 'however', 'important', 'importante', 'importantes', 'manera', 'moreover', 'mundo', 'overall', 'provide', 'resumen', 'should', 'siempre', 'significant', 'significativa', 'significativas', 'significativo', 'significativos', 'también', 'their', 'there', 'these', 'those', 'today', 'través', 'various', 'which', 'world', 'would'];

    public const RASGOS = [
        'sl_mean' => ['Longitud media de oración', 'palabras por oración'],
        'sl_cv' => ['Variabilidad entre oraciones (burstiness)', 'coef. de variación; los humanos alternan oraciones cortas y largas'],
        'mattr' => ['Diversidad léxica (MATTR)', 'proporción de palabras distintas en ventanas de 50'],
        'wlen' => ['Longitud media de palabra', 'letras por palabra'],
        'long' => ['Palabras largas (≥7 letras)', 'proporción'],
        'comma' => ['Densidad de comas', 'comas por palabra'],
        'semi' => ['Punto y coma', 'por palabra'],
        'colon' => ['Dos puntos', 'por palabra'],
        'dash' => ['Rayas (— / –)', 'por palabra'],
        'paren' => ['Paréntesis', 'por palabra'],
        'quote' => ['Comillas', 'por palabra'],
        'quest' => ['Preguntas', 'signos ? por palabra'],
        'excl' => ['Exclamaciones', 'signos ! por palabra'],
        'ellip' => ['Puntos suspensivos', 'por palabra'],
        'digit' => ['Cifras y números', 'proporción de palabras con dígitos'],
        'bullet' => ['Listas con viñetas', 'proporción de líneas'],
        'md' => ['Formato Markdown (## / **)', 'proporción de líneas'],
        'para' => ['Densidad de párrafos/líneas', 'líneas cada 100 palabras'],
        'stop' => ['Palabras funcionales', 'proporción (de, que, the, of…)'],
        'p1' => ['Primera persona (yo, mi, I, my)', 'proporción'],
        'p2' => ['Segunda persona (tú, usted, you)', 'proporción'],
        'contr' => ['Apóstrofos / contracciones', 'por palabra'],
        'startrep' => ['Repetición de inicios de oración', 'proporción de oraciones que empiezan igual que otra'],
        'emoji' => ['Emojis', 'por palabra'],
    ];

    /** Nombres comerciales de las familias que el clasificador conoce. */
    public const FAMILIAS = [
        'openai' => 'ChatGPT / GPT (OpenAI)',
        'anthropic' => 'Claude (Anthropic)',
        'google' => 'Gemini (Google)',
        'meta' => 'Llama (Meta)',
        'mistral' => 'Mistral / Mixtral',
        'cohere' => 'Command (Cohere)',
        'otros' => 'Otros (Yandex, GigaChat, AI21, Qwen, BLOOM…)',
    ];

    /** Marcadores informativos (NO suman al puntaje). Lista idéntica a motor-vat.js. */
    public const MARCADORES = [
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
        ['in essence', 'google', 'Fórmula de síntesis frecuente en Gemini'],
    ];

    private const IDIOMA_ES = 'el la los las de que y en un una es por con para del se no su al lo como más pero muy también este esta porque cuando';
    private const IDIOMA_EN = 'the of and to in is that it for on are as with be at this from by or not but have was were which their will';

    /** Idioma por palabras funcionales; avisa si no es español/inglés (fiabilidad reducida). */
    public static function idioma(array $palabras): array
    {
        $es = array_flip(explode(' ', self::IDIOMA_ES));
        $en = array_flip(explode(' ', self::IDIOMA_EN));
        $ce = 0;
        $ci = 0;
        foreach ($palabras as $w) {
            if (isset($es[$w])) $ce++;
            if (isset($en[$w])) $ci++;
        }
        if (($ce + $ci) / max(count($palabras), 1) < 0.08) {
            return ['codigo' => 'otro', 'nombre' => 'No identificado', 'soportado' => false];
        }
        return $ce >= $ci ? ['codigo' => 'es', 'nombre' => 'Español', 'soportado' => true]
            : ['codigo' => 'en', 'nombre' => 'Inglés', 'soportado' => true];
    }

    /** Marcadores encontrados (búsqueda por subcadena en minúsculas). */
    public static function marcadores(string $texto): array
    {
        $bajo = mb_strtolower($texto, 'UTF-8');
        $res = [];
        foreach (self::MARCADORES as [$frase, $fam, $desc]) {
            $veces = substr_count($bajo, $frase);
            if ($veces > 0) {
                $res[] = ['frase' => $frase, 'familia' => $fam, 'descripcion' => $desc, 'ocurrencias' => $veces];
            }
        }
        return $res;
    }

    /**
     * Reconstrucción del prompt más probable (inferencia, nunca literal).
     * Mismas reglas que estimarPrompt() en motor-vat.js.
     */
    public static function prompt(string $texto, array $dens, array $idioma): array
    {
        $v = $dens['valores'];
        $palabras = $dens['palabras'];
        $bajo = mb_strtolower($texto, 'UTF-8');

        $freq = [];
        foreach ($palabras as $w) {
            if (mb_strlen($w, 'UTF-8') >= 5 && !Estilometria::esFuncional($w) && !in_array($w, self::GENERICOS, true) && !preg_match('/[0-9]/', $w)) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        // Orden: frecuencia descendente y, a igualdad, alfabético (igual que JS) para resultados deterministas.
        uksort($freq, static function ($a, $b) use ($freq) {
            return ($freq[$b] <=> $freq[$a]) ?: strcmp((string) $a, (string) $b);
        });
        $tema = array_slice(array_map('strval', array_keys($freq)), 0, 4);

        $tiene = static function (array $lista) use ($bajo): bool {
            foreach ($lista as $w) {
                if (mb_strpos($bajo, $w) !== false) return true;
            }
            return false;
        };
        $genero = 'texto expositivo';
        if ($v['md'] > 0.15 || $v['bullet'] > 0.2) $genero = 'guía o explicación estructurada con títulos y viñetas';
        elseif ($v['quote'] > 0.01 && $v['p1'] > 0.01) $genero = 'relato o cuento narrativo';
        elseif ($tiene(['estudio', 'investigación', 'análisis', 'metodología', 'study', 'research', 'analysis', 'hypothesis'])) $genero = 'ensayo o párrafo académico';
        elseif ($tiene(['según', 'declaró', 'informó', 'anunció', 'said', 'reported', 'according to'])) $genero = 'noticia periodística';
        elseif ($tiene(['recomiendo', 'producto', 'hotel', 'servicio', 'calidad', 'recommend', 'product', 'service'])) $genero = 'reseña u opinión de producto/servicio';
        elseif ($v['p2'] > 0.015) $genero = 'texto instructivo dirigido al lector';
        elseif ($tiene(['en conclusión', 'en resumen', 'in conclusion', 'por otro lado', 'on the other hand'])) $genero = 'ensayo argumentativo';

        $n = count($palabras);
        // floor($x + 0.5) replica Math.round de JS (round() de PHP redondea .5 hacia afuera y puede diferir).
        $largo = $n < 80 ? 'breve (' . max(10, (int) floor($n / 10 + 0.5) * 10) . ' palabras aprox.)'
            : 'de unas ' . ((int) floor($n / 50 + 0.5) * 50) . ' palabras';
        $tono = ($v['excl'] > 0.01 || $v['emoji'] > 0 || $v['contr'] > 0.02) ? 'cercano/informal'
            : ($v['wlen'] > 5.2 ? 'formal y técnico' : 'neutro e informativo');
        $parrafos = count($dens['lineas']);
        $estructura = $v['md'] > 0.15 ? ', con encabezados y listas'
            : ($parrafos >= 3 ? ', organizado en ' . $parrafos . ' párrafos con introducción y conclusión' : '');
        $lenguaje = $idioma['codigo'] === 'en' ? ' en inglés' : '';
        $temaTxt = $tema ? implode(', ', $tema) : 'el tema del texto';
        return [
            'texto' => 'Escribe un ' . $genero . ' ' . $largo . $lenguaje . ' sobre "' . $temaTxt . '", con tono ' . $tono . $estructura . '.',
            'genero' => $genero, 'tema' => $tema, 'tono' => $tono, 'longitud' => $n,
            'nota' => 'Reconstrucción inferida a partir de género, tema, extensión, estructura y tono. Ningún detector puede recuperar el prompt literal.',
        ];
    }

    /** Formato numérico con coma decimal (igual que fmt() en JS). */
    public static function fmt(float $x, int $d): string
    {
        $f = 10 ** $d;
        $r = floor($x * $f + 0.5) / $f;
        $s = rtrim(rtrim(number_format($r, $d, '.', ''), '0'), '.');
        return str_replace('.', ',', $s === '-0' ? '0' : $s);
    }

    /** Recorta citas largas (legibilidad en celular). */
    public static function recortar(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max - 1, 'UTF-8') . '…' : $s;
    }
}
