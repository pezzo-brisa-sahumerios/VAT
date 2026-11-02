# VAT · Datos, calibración y fiabilidad

## 1. Corpus de entrenamiento (textos reales)

Modelo v2: **151 129 textos** tras deduplicar (≥ 25 palabras cada uno): 65 594 humanos y 85 535 generados por IA, en español (42 557) e inglés (108 572). El modelo v1 usaba 53 679.

| Fuente | Contenido | Modelos |
|---|---|---|
| IberAuTexTification 2024 (IberLEF) | Noticias, reseñas, tweets, legal, wiki… humanos vs IA | Llama-2-70B, Command, Jurassic-2, GPT-3.5, Mixtral-8x7B, GPT-4 |
| AuTexTification 2023 (IberLEF) | Humano vs generado, ES/EN | Modelos BLOOM/GPT-3 |
| Corpus paralelo de dominios (artnitolog) | Mismo encargo resuelto por un humano y por cada modelo | GPT-4 Turbo, GPT-4o, Claude 3 Opus, Llama-3-70B, Command R+, YandexGPT, GigaChat |
| Resúmenes académicos (arXiv vs IA) | Resúmenes científicos | Gemini 2.0 Flash, GPT-3.5 |
| TextSight 2026 | Wikipedia, literatura, PubMed vs IA | GPT, Claude y otros |
| Claude Sonnet 4.6 | Respuestas largas | Claude Sonnet 4.6 |
| Wikipedia ES vs Llama 3.2 | Artículos enciclopédicos | Llama 3.2 |
| Conversaciones Gemini | Respuestas de chat | Gemini |
| COLING 2025 MGT (M4GT, MAGE, HC3, OUTFOX…) — **nuevo en v2** | Humanos de 24 subfuentes (Reddit, arXiv, Wikipedia, WikiHow, ensayos de estudiantes, noticias, reseñas, medicina, finanzas…) vs IA | GPT-4o, GPT-4, GPT-3.5, Llama-3 8B/70B, Gemma/Gemma 2, Mixtral-8x7B, Cohere, davinci-003 |
| RAID (benchmark adversarial) — **nuevo en v2** | Resúmenes científicos, con IA atacada: paráfrasis, sinónimos, faltas, artículos borrados, ortografía alternativa, párrafos insertados | ChatGPT, GPT-4, Llama-chat, Mistral-chat, Cohere-chat |

Los corpus no se distribuyen en el zip (licencias y tamaño); el zip contiene el modelo resultante y los scripts para reproducirlo.

## 2. Método

1. División estratificada 70/15/15 (entrenamiento / validación para calibrar y fijar umbrales / prueba final) por etiqueta, idioma y fuente.
2. Rasgos: 55 499 unigramas/bigramas (selección chi² humano/IA y por familia) con TF sublineal y L2, más 24 rasgos estilométricos estandarizados.
3. Regresión logística binaria (C = 10, clases balanceadas).
4. Calibración de Platt sobre el 15 % de validación → probabilidad.
5. Umbral IA = percentil 99,5 de los humanos de validación (0,9309: margen extra para que en textos nuevos siga por debajo de 1 %); umbral humano = 0,2088 (deja por debajo sólo el 3 % de los textos IA de validación).
6. Clasificador multinomial de familias entrenado sólo con textos IA.
7. Exportación a JSON con pesos enteros (×1000) y prueba de paridad Python ↔ JS ↔ PHP sobre 300 textos: diferencia máxima 2,2·10⁻¹⁶ (JS) y 0 (PHP).

## 3. Resultados en el conjunto de prueba (22 670 textos nunca vistos)

| Conjunto | FP (humanos acusados) | IA detectada | No concluyente |
|---|---|---|---|
| Global | 0,66 % | 74,8 % | 21,2 % |
| Español | 0,91 % | 58,3 % | 30,8 % |
| Inglés | 0,53 % | 80,2 % | 17,4 % |
| 25–60 palabras | 0,92 % | 34,3 % | 35,7 % |
| 250+ palabras | 0,49 % | 87,9 % | 11,9 % |

AUC = 0,9767 · Precisión de familia = 82,2 %. El conjunto de prueba v2 es mucho más variado y difícil que el de v1 (más modelos, más dominios humanos y textos atacados), por eso las cifras no se comparan directamente con las de v1: la comparación justa está en la sección 3 bis.

## 3 bis. Antes (v1) vs después (v2) sobre los mismos textos

Mismos 21 477 textos de prueba v2 (≥ 40 palabras), cada modelo con sus umbrales. Nota: v1 pudo haber visto en su entrenamiento algunos de estos textos, así que su cifra es, si acaso, optimista.

| Conjunto | v1 FP | v1 IA detectada | v2 FP | v2 IA detectada |
|---|---|---|---|---|
| Global | 1,27 % | 61,5 % | **0,62 %** | **76,2 %** |
| Inglés | 1,23 % | 61,3 % | 0,55 % | 81,1 % |
| Español | 1,36 % | 62,1 % | 0,79 % | 60,5 % |
| GPT-4o (COLING) | 2,08 % | 80,4 % | 0,80 % | 99,4 % |
| IA parafraseada (RAID) | 0 % | 4,1 % | 0 % | 63,6 % |
| IA con sinónimos (RAID) | 0 % | 12,8 % | 0 % | 85,1 % |
| 120–250 palabras | 0,63 % | 45,9 % | 0,37 % | 79,0 % |
| 250+ palabras | 0,14 % | 78,6 % | 0,51 % | 87,9 % |

AUC global: 0,929 → 0,980.

**Benchmarks externos (ninguno usado para entrenar):**

| Benchmark | v1 FP | v1 IA detectada | v2 FP | v2 IA detectada |
|---|---|---|---|---|
| MAGE OOD: GPT-4 parafraseado | 1,97 % | 26,5 % | 0,26 % | 16,7 % |
| MAGE OOD: GPT-4 | 1,97 % | 52,4 % | 0,26 % | 39,8 % |
| HVSIA (español, Qwen2.5 vs Wikipedia) | 2,0 % | 100 % | 2,0 % | 100 % |

Lectura honesta: en MAGE v2 detecta menos IA porque es más estricto (acusa a 8 veces menos humanos); su AUC sí mejoró (0,842 → 0,876 y 0,915 → 0,928). El español casi no mejoró en detección (sí bajaron los falsos positivos): hacen falta más corpus de IA moderna en español.

**Probado y descartado:** fragmentos de 4 letras dentro de las palabras (AUC 0,9740 frente a 0,9767 sin ellos) y peso igual por fuente humana (AUC 0,9730 y menos detección en español). Ninguno entró al modelo final. Valores exactos por tramo en `metricas-modelo.json` (y visibles en la propia app, sección "Calibración").

## 4. Prueba de robustez del modelo v1: fuente excluida del entrenamiento (leave-one-source-out)

Para simular textos "de fuera" se reentrenó excluyendo una fuente completa y se evaluó sólo sobre ella:

| Fuente excluida | FP humanos | IA detectada | No concluyente |
|---|---|---|---|
| Claude 3 Opus (corpus paralelo) | — | 96,1 % | 3,3 % |
| Claude Sonnet 4.6 | — | 74,6 % | 22,3 % |
| Gemini 2.0 Flash (académico) | — | 62,7 % | 30,2 % |
| TextSight 2026 (humano + IA) | 1,1 % | 33,9 % | 20,4 % |
| Wikipedia ES vs Llama 3.2 | **13,9 %** | 36,1 % | 44,7 % |

Lectura honesta: ante modelos y dominios no vistos, VAT sigue sin acusar casi nunca y prefiere "No concluyente"; la excepción fue el texto enciclopédico en español (Wikipedia), cuyo estilo neutro y formal se parece al de la IA. Por eso esa fuente **está incluida** en el modelo final. Esto demuestra la principal limitación de cualquier detector: un estilo humano muy formal y sin rasgos personales (enciclopedias, textos legales) es el caso más difícil.

## 5. Reentrenamiento

```bash
pip install numpy pandas pyarrow scikit-learn
# Colocar los corpus en /home/ubuntu/data (o cambiar D en prep.py)
python3 entrenamiento/prep.py      # → corpus2.jsonl + dense2.npy
python3 entrenamiento/train.py 60000 10 _v6 0.995   # → model_v6.pkl + metrics_v6.json
python3 entrenamiento/bench.py modelo/vat-modelo.json  # benchmarks externos
python3 entrenamiento/export.py    # → modelo/vat-modelo.json y vat-modelo.js
node    entrenamiento/parity.js    # verifica JS == Python
php     entrenamiento/parity.php   # verifica PHP == Python
```
Para mejorar un dominio concreto (p. ej. trabajos escolares), agregá textos humanos y de IA de ese dominio en `prep.py`: es la mejora más efectiva.
