# VAT · Detector Forense de Texto IA

Detector de texto generado por inteligencia artificial (ChatGPT/GPT, Claude, Gemini, Llama, Mistral, Command y otros), **100 % local y sin APIs**: el texto nunca sale de tu servidor ni de tu celular.

- Probabilidad **calibrada** (no un "puntaje inventado"): si VAT dice 95 %, aproximadamente 95 de cada 100 textos con ese puntaje eran IA en la validación.
- **Veredicto conservador**: sólo dice "Generado por IA" por encima de un umbral elegido para acusar a menos del 1 % de textos humanos. En la zona dudosa responde **"No concluyente"** en vez de inventar.
- **Atribución de modelo** (familia más probable) con sus probabilidades.
- **Informe forense**: posible prompt utilizado, oraciones citadas, n-gramas y rasgos que decidieron, mapa de oraciones coloreado, proceso paso a paso y tabla de fiabilidad medida.
- Interfaz violeta oscura, responsiva, instalable en el celular (PWA) y que funciona **sin conexión**.

---

## 1. Uso rápido

### Opción A — Servidor con PHP (recomendada para que otros la usen)
Requisitos: PHP ≥ 7.4 con `mbstring` e `intl` (incluidas en casi todos los hostings).

```bash
cd VAT-Detector
php -S 0.0.0.0:8080        # o subí la carpeta a tu hosting (Apache/Nginx + PHP)
# abrí http://localhost:8080
```
El indicador superior mostrará **"Motor PHP · servidor"**.

### Opción B — Celular / sin servidor (offline)
Descomprimí el zip y abrí `index.html` con el navegador (o servilo desde cualquier hosting estático, p. ej. GitHub Pages). El indicador mostrará **"Motor local · offline"**: el análisis corre en JavaScript dentro del teléfono con **el mismo modelo y las mismas fórmulas** que PHP (verificado: diferencia máxima 3·10⁻¹⁶).
Servido por HTTPS se puede "Agregar a pantalla de inicio" y funciona sin internet.

> Si el servidor PHP falla o no existe, la interfaz cambia sola al motor local: la herramienta nunca queda inutilizable.

### Endpoint PHP (opcional, para integraciones propias)
```
POST /api/analizar.php     Content-Type: application/json
{"texto": "..."}           → JSON con probabilidad_ia, veredicto, atribucion, evidencias, prompt_estimado, proceso, calibracion…
GET  /api/analizar.php     → estado del servicio
```
Límites: 60 000 caracteres por texto y 30 análisis por minuto por IP (editables en `config/config.php`).

---

## 2. Estructura

```
VAT-Detector/
├── index.html                 Interfaz (workspace de 2 paneles, máx. 1100 px)
├── manifest.webmanifest       PWA (instalable en celular)
├── sw.js                      Service worker (uso offline)
├── .htaccess                  Seguridad para Apache (bloquea src/, config/, entrenamiento/)
├── assets/
│   ├── css/estilos.css        Tema violeta oscuro, responsive, barras con transiciones
│   ├── js/app.js              Controlador de la interfaz (elige motor PHP o JS)
│   ├── js/motor-vat.js        Motor forense en JavaScript (idéntico al de PHP)
│   └── img/icono.svg          Logo VAT
├── api/analizar.php           Endpoint JSON (validación, límite de tasa, cabeceras seguras)
├── config/config.php          Constantes y protocolo forense
├── src/
│   ├── CargadorModelo.php     Carga/cachea el modelo y valida su integridad
│   ├── Tokenizador.php        Normalización Unicode, tokens, n-gramas, oraciones
│   ├── Estilometria.php       24 rasgos estilométricos
│   ├── ClasificadorVAT.php    Regresión logística + Platt + softmax de familias
│   ├── Explicador.php         Idioma, marcadores, prompt estimado, textos legibles
│   └── MotorForense.php       Orquesta el análisis completo
├── modelo/
│   ├── vat-modelo.json        Modelo entrenado (usado por PHP)
│   └── vat-modelo.js          Mismo modelo embebido (usado offline por JS)
├── entrenamiento/             Scripts Python reproducibles (preparación, entrenamiento, exportación, pruebas de paridad)
└── docs/
    ├── CALIBRACION.md         Datos, método, métricas reales y limitaciones
    └── metricas-modelo.json   Métricas crudas del conjunto de prueba
```

---

## 3. Cómo detecta (protocolo de analizador lingüístico forense)

VAT no "le pregunta" a otra IA: aplica un protocolo forense determinista, fijado en `config/config.php`:

> *Actúa como analizador lingüístico forense: no adivines; mide n-gramas y rasgos estilométricos, compáralos con distribuciones aprendidas de textos reales, calibra la probabilidad, acusa de IA sólo por encima del umbral de ≤1 % de falsos positivos y justifica cada conclusión con evidencia citada.*

1. **Normalización** Unicode (NFC) y tokenización de palabras y signos.
2. **Huella léxica**: 55 499 unigramas/bigramas seleccionados (TF sublineal + normalización L2). Capturan las "muletillas" estadísticas de los modelos ("es importante destacar", "en resumen", "delve", "crucial"…) y también las marcas humanas (coloquialismos, errores, primera persona).
3. **Estilometría**: 24 rasgos — longitud y *variación* de oraciones (burstiness), diversidad léxica (MATTR), puntuación, rayas, listas, Markdown, contracciones, pronombres, emojis, etc.
4. **Regresión logística** entrenada con ~151 000 textos reales (GPT-4o, GPT-4, Claude, Gemini/Gemma, Llama 3, Mixtral, Cohere, Qwen, IA parafraseada…) → puntaje z.
5. **Calibración de Platt** → probabilidad real.
6. **Veredicto con dos umbrales** (IA ≥ 93,1 %, humano ≤ 20,9 %; en medio, "No concluyente"). Textos de menos de 40 palabras → "Texto demasiado corto".
7. **Atribución**: clasificador multinomial de familias (OpenAI, Anthropic, Google, Meta, Mistral, Cohere, otros), sólo si hay evidencia de IA.
8. **Explicación**: cada término y rasgo tiene su aporte exacto al puntaje; se citan las oraciones con más señal y se reconstruye el posible prompt (género, tema, tono, longitud, idioma).

---

## 4. Fiabilidad medida (modelo v2, conjunto de prueba nunca visto, 22 670 textos)

| Conjunto | Humanos acusados (FP) | IA detectada | No concluyente |
|---|---|---|---|
| Global | **0,66 %** | 74,8 % | 21,2 % |
| Español | 0,91 % | 58,3 % | 30,8 % |
| Inglés | 0,53 % | 80,2 % | 17,4 % |
| 250+ palabras | 0,49 % | 87,9 % | 11,9 % |
| 25–60 palabras | 0,92 % | 34,3 % | 35,7 % |

Sobre los mismos textos, v1 acusaba al 1,27 % de los humanos y detectaba el 61,5 % de la IA; v2 acusa al 0,62 % y detecta el 76,2 % (IA parafraseada: 4 % → 64 %). AUC = 0,977 · Precisión de familia (entre textos IA) = 82,2 %. Detalle completo y pruebas con fuentes excluidas del entrenamiento en [`docs/CALIBRACION.md`](docs/CALIBRACION.md).

---

## 5. Desventajas típicas de los detectores y cómo las aborda VAT

| Desventaja típica | Solución en VAT |
|---|---|
| Acusan a humanos (falsos positivos) | Umbral fijado para < 1 % de FP + zona "No concluyente" |
| Porcentajes sin significado | Probabilidad calibrada con Platt y publicada junto a sus métricas |
| "Caja negra" | Evidencias citadas: oraciones, n-gramas, rasgos y aporte de cada uno |
| Fallan con textos cortos | Se exige un mínimo (40 palabras) y se advierte la menor fiabilidad |
| Sólo inglés | Entrenado en español e inglés |
| Envían tu texto a terceros | 100 % local, sin APIs, sin cookies, sin guardar nada |
| Sólo funcionan online | Motor JS offline + PWA instalable |
| Penalizan listas de palabras "sospechosas" | Los marcadores discursivos se muestran pero **no** suman puntaje; decide el modelo estadístico |

---

## 6. Limitaciones honestas

- **Ningún detector es infalible**, tampoco VAT. Úsalo como evidencia, no como sentencia, sobre todo en decisiones académicas o laborales.
- Textos de IA **parafraseados, editados a mano o mezclados** con texto humano tienden a dar "No concluyente".
- La **identificación del modelo exacto** es orientativa (82,2 % en familias conocidas); modelos nuevos o con estilo pedido explícitamente pueden confundirse.
- Fuera de español/inglés y de dominios similares a los de entrenamiento la fiabilidad baja (ver pruebas de fuente excluida en `docs/CALIBRACION.md`).
- El "posible prompt" es una reconstrucción inferida, no el prompt real.

---

## 7. Reentrenar / recalibrar

Ver `docs/CALIBRACION.md` §5. Resumen: colocar los corpus en `data/`, ejecutar `entrenamiento/prep.py → train.py → export.py`, y verificar con `parity.js` / `parity.php`. El nuevo `modelo/vat-modelo.json` y `vat-modelo.js` se usan automáticamente sin cambiar código.

## Licencia
MIT. Los corpus de entrenamiento pertenecen a sus autores y no se incluyen en el zip.
