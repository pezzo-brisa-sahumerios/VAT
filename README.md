# VAT — Virus, AI, Test

Detector de texto generado por IA + humanizador, con backend en PHP
consumiendo modelos reales de Hugging Face.

## Estructura del proyecto

```
index.html      → interfaz (workspace dividido: entrada | resultados)
style.css       → paleta violeta/oscura, animaciones
app.js          → lógica de frontend (fetch async/await hacia detector.php)
config.php      → tokens, URLs de los modelos, parámetros de red
prompts.php     → TODOS los textos que se le mandan a la IA — editá acá,
                  no en detector.php, para cambiar el estilo de humanización
detector.php    → backend: valida la petición, llama a Hugging Face, devuelve JSON

entrenamiento/  → todo lo necesario para entrenar tu propio clasificador
  ├─ consignas_50.txt                        → las 50 preguntas usadas para armar el dataset
  ├─ chatgpt.txt / claude.txt                → respuestas ya recolectadas y limpias
  ├─ VAT_entrenamiento_clasificador.ipynb    → notebook de Google Colab (gratis)
```

## Puesta en marcha (en orden)

### 1. Token de Hugging Face
`huggingface.co/settings/tokens` → `Create new token` → tipo **Read**.
Pegalo en `config.php`, en `HF_API_TOKEN`.

⚠️ Si en algún momento pegaste un token en un chat, documento o repositorio
público: revocalo y generá uno nuevo antes de usar este proyecto en serio.

### 2. Licencia de Llama-3-8B-Instruct (solo para humanizar)
Entrá a `huggingface.co/meta-llama/Meta-Llama-3-8B-Instruct` con la misma
cuenta del token y aceptá el formulario de licencia. Sin este paso, la
acción "Humanizar" devuelve error 403.

### 3. Subir los archivos a un hosting con PHP
Subí `index.html`, `style.css`, `app.js`, `config.php`, `prompts.php` y
`detector.php` (los 6, mismo nivel de carpeta). La carpeta `entrenamiento/`
no hace falta subirla al hosting — es solo para vos, en tu computadora.

### 4. Probar
Abrí `index.html` desde el dominio de tu hosting (no como archivo local:
`file://` no puede hacer peticiones POST a `detector.php`). Pegá un texto,
apretá "Analizar texto" y "Humanizar contenido".

## Estado actual del sistema (honesto, sin vueltas)

- **Humano vs IA**: calibrado, sale de un clasificador real. Desde esta
  versión, `detector.php` prueba una LISTA de modelos candidatos en orden
  (`HF_CLASSIFIER_CANDIDATOS` en `config.php`) y usa el primero que
  responda bien — si Hugging Face deja de servir uno (como pasó con
  `Hello-SimpleAI/chatgpt-detector-roberta`), prueba el siguiente
  automáticamente, sin que tengas que tocar código.
- **Claude / ChatGPT / Gemini**: todavía es un reparto en partes iguales
  (no hay forma de distinguirlos sin el modelo propio entrenado). El aviso
  en pantalla lo aclara siempre.
- **Humanización**: real, usa Llama-3-8B-Instruct para reescribir.

## Cómo pasar al modelo propio (cuando esté entrenado)

1. Corré `entrenamiento/VAT_entrenamiento_clasificador.ipynb` en Google
   Colab (gratis, con GPU). Vas a necesitar `gemini.txt` además de los
   `chatgpt.txt`/`claude.txt` que ya están en la carpeta.
2. Cuando el notebook termine, vas a tener un modelo propio en
   `huggingface.co/TU_USUARIO/vat-clasificador-es`.
3. En `config.php`, agregalo AL PRINCIPIO de `HF_CLASSIFIER_CANDIDATOS`:
   ```php
   define('HF_CLASSIFIER_CANDIDATOS', array(
       array('id' => 'TU_USUARIO/vat-clasificador-es', 'tipo' => 'text-classification-4clases'),
       array('id' => 'Hello-SimpleAI/chatgpt-detector-roberta', 'tipo' => 'text-classification'),
       array('id' => 'openai-community/roberta-base-openai-detector', 'tipo' => 'text-classification'),
       array('id' => 'MoritzLaurer/mDeBERTa-v3-base-mnli-xnli', 'tipo' => 'zero-shot-classification'),
   ));
   ```
4. Listo — `detector.php` ya tiene el código para leer las 4 clases reales
   apenas encuentre un candidato con `tipo='text-classification-4clases'`,
   no hace falta tocar nada más. Los demás candidatos quedan como
   respaldo, por si el modelo propio alguna vez no responde.
