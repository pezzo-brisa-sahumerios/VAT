/* =============================================================================
 * VAT · Controlador de la interfaz (JavaScript ES6 nativo)
 * -----------------------------------------------------------------------------
 * Responsabilidades: detectar qué motor usar (PHP o JS local), enviar el texto,
 * pintar resultados con barras animadas y construir el informe forense.
 * Si este archivo no se cargara, el botón "Analizar" no haría nada.
 * ============================================================================= */
(function () {
  'use strict';

  /* Referencias al DOM obtenidas una sola vez. Sin cachearlas, se buscaría en
   * el documento en cada actualización (más lento en celulares). */
  const $ = function (id) { return document.getElementById(id); };
  const el = {
    entrada: $('entrada'), contador: $('contador'), analizar: $('btnAnalizar'), limpiar: $('btnLimpiar'),
    estado: $('estadoMotor'), vacio: $('vacio'), resultado: $('resultado'), informe: $('informe')
  };

  /* Motor activo: 'php' si el endpoint responde, si no 'js'. Se decide al
   * cargar. Sin esta detección, abrir el zip en un celular (sin PHP) daría
   * siempre error de red. */
  let motor = 'js';
  const URL_API = 'api/analizar.php';

  /* ---------------------------------------------------------------------------
   * escapar(texto): convierte < > & " ' en entidades HTML.
   * IMPRESCINDIBLE: el texto del usuario se muestra en el informe. Sin escapar,
   * pegar "<img src=x onerror=...>" ejecutaría código (XSS).
   * ------------------------------------------------------------------------- */
  function escapar(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  /* Porcentaje con coma decimal (formato hispano). */
  function pct(x, d) { return (x * 100).toFixed(d === undefined ? 1 : d).replace('.', ',') + '%'; }
  /* Probabilidad mostrada acotada a 0,1 %–99,9 %: un modelo estadístico nunca
   * tiene certeza absoluta. Sin este límite el redondeo mostraría "100,0 %",
   * sugiriendo una seguridad que no existe. */
  function prob(x) { return pct(Math.min(0.999, Math.max(0.001, x))); }

  /* Detecta si PHP está disponible con un GET de estado y timeout de 2,5 s.
   * Sin timeout, un servidor colgado dejaría la insignia en "Conectando…" para siempre. */
  async function detectarMotor() {
    if (location.protocol === 'file:') { return marcarMotor('js'); } // Abierto como archivo: no hay servidor posible.
    try {
      const ctrl = new AbortController();
      const t = setTimeout(function () { ctrl.abort(); }, 2500);
      const r = await fetch(URL_API, { method: 'GET', signal: ctrl.signal, cache: 'no-store' });
      clearTimeout(t);
      const j = await r.json();
      marcarMotor(j && j.ok ? 'php' : 'js');
    } catch (e) {
      marcarMotor('js'); // Cualquier fallo (404, HTML en vez de JSON, sin red) → motor local.
    }
  }
  function marcarMotor(m) {
    motor = m;
    el.estado.textContent = m === 'php' ? 'Motor PHP · servidor' : 'Motor local · offline';
    el.estado.classList.add('insignia--ok');
  }

  /* Contador de palabras en vivo: ayuda a saber si el texto alcanza el mínimo fiable. */
  function actualizarContador() {
    const n = (el.entrada.value.match(/[\p{L}\p{N}]+/gu) || []).length;
    el.contador.textContent = n + (n === 1 ? ' palabra' : ' palabras') + (n > 0 && n < 40 ? ' · mínimo 40' : '');
  }

  /* ---------------------------------------------------------------------------
   * analizar(): punto de entrada del botón.
   * Intenta PHP y, ante cualquier error, recurre al motor JS (mismo modelo y
   * mismas fórmulas → mismo resultado). Sin este respaldo, una caída del
   * hosting dejaría la herramienta inutilizable.
   * ------------------------------------------------------------------------- */
  async function analizar() {
    const texto = el.entrada.value;
    if (!texto.trim()) { el.entrada.focus(); return; } // Nada que analizar: evita una petición inútil.
    el.analizar.disabled = true; el.analizar.classList.add('cargando');   // Evita dobles clics y muestra progreso.
    let res = null;
    try {
      if (motor === 'php') {
        try {
          const r = await fetch(URL_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ texto: texto }) });
          res = await r.json();
          if (!res || (!res.ok && r.status >= 500)) res = null; // Error del servidor → usar motor local.
        } catch (e) { res = null; }
      }
      if (!res) {
        // setTimeout 30ms permite que el navegador pinte el indicador de carga antes del cálculo síncrono.
        await new Promise(function (ok) { setTimeout(ok, 30); });
        if (!window.MotorVAT || !window.VAT_MODELO) throw new Error('No se pudo cargar el modelo local (modelo/vat-modelo.js).');
        res = window.MotorVAT.analizar(texto, window.VAT_MODELO);
      }
      if (!res.ok) throw new Error(res.error || 'Error desconocido');
      pintar(res);
    } catch (e) {
      mostrarError(e.message);
    } finally {
      el.analizar.disabled = false; el.analizar.classList.remove('cargando'); // finally: se rehabilita el botón aunque haya error.
    }
  }

  function mostrarError(msg) {
    el.vacio.hidden = false; el.resultado.hidden = true; el.informe.hidden = true;
    el.vacio.innerHTML = '<div class="vacio__icono">!</div><p>' + escapar(msg) + '</p>';
  }

  /* Anima una barra: primero 0 y en el siguiente cuadro el valor final, para
   * que la transición CSS se dispare. Sin el doble requestAnimationFrame el
   * navegador aplicaría ambos cambios juntos y no habría animación. */
  function animarBarra(nodo, valor) {
    nodo.style.width = '0';
    requestAnimationFrame(function () { requestAnimationFrame(function () { nodo.style.width = Math.max(0, Math.min(100, valor * 100)) + '%'; }); });
  }

  /* ---------------------------------------------------------------------------
   * pintar(res): vuelca el informe en la página.
   * Todo texto variable pasa por escapar() antes de insertarse como HTML.
   * ------------------------------------------------------------------------- */
  function pintar(r) {
    el.vacio.hidden = true; el.resultado.hidden = false; el.informe.hidden = false;
    const v = r.veredicto;
    $('veredicto').className = 'veredicto veredicto--' + v.codigo;
    $('veredictoTitulo').textContent = v.titulo;      // textContent: inserción segura sin interpretar HTML.
    $('veredictoDetalle').textContent = v.detalle;
    $('valIA').textContent = prob(r.probabilidad_ia);
    $('valHum').textContent = prob(r.probabilidad_humano);
    $('valConf').textContent = pct(v.confianza, 0);
    animarBarra($('barIA'), r.probabilidad_ia);
    animarBarra($('barHum'), r.probabilidad_humano);
    animarBarra($('barConf'), v.confianza);
    $('marcaIA').style.left = (r.calibracion.umbral_ia * 100) + '%';
    $('marcaHum').style.left = (r.calibracion.umbral_humano * 100) + '%';

    // Avisos: idioma no soportado y posible texto mixto.
    let avisos = '';
    if (!r.idioma.soportado) avisos += '<div class="alerta">Idioma no identificado como español o inglés: la fiabilidad es menor.</div>';
    if (r.mixto.posible) avisos += '<div class="alerta">' + escapar(r.mixto.detalle) + '</div>';
    $('avisos').innerHTML = avisos;

    // Atribución por familia (sólo si hay evidencia de IA).
    const fam = $('familias');
    if (r.atribucion.disponible) {
      fam.innerHTML = r.atribucion.familias.map(function (f, i) {
        return '<div class="familia' + (i === 0 ? ' familia--top' : '') + '"><div class="familia__fila"><span>' + escapar(f.nombre) + '</span><strong>' + prob(f.prob) + '</strong></div>' +
          '<div class="barra"><div class="barra__relleno barra__relleno--fam" data-v="' + f.prob + '"></div></div></div>';
      }).join('');
      fam.querySelectorAll('[data-v]').forEach(function (b) { animarBarra(b, parseFloat(b.getAttribute('data-v'))); });
      $('notaFamilia').textContent = r.atribucion.nota;
    } else {
      fam.innerHTML = '<p class="tenue pequeno">No se estima modelo: el texto no muestra evidencia suficiente de IA.</p>';
      $('notaFamilia').textContent = '';
    }

    const e = r.estadisticas;
    $('estadisticas').innerHTML = [['Palabras', e.palabras], ['Oraciones', e.oraciones], ['Idioma', r.idioma.nombre], ['Motor', (r.motor === 'php' ? 'PHP' : 'JS local') + ' · ' + r.tiempo_ms + ' ms']]
      .map(function (x) { return '<li><b>' + escapar(x[1]) + '</b>' + x[0] + '</li>'; }).join('');

    // Informe: prompt estimado.
    $('prompt').textContent = r.prompt_estimado.texto;
    $('promptNota').textContent = r.prompt_estimado.nota;

    // Evidencias citadas.
    $('evidencias').innerHTML = r.evidencias.map(function (x) { return '<li class="' + escapar(x.tipo) + '">' + escapar(x.texto) + '</li>'; }).join('') || '<li>Sin evidencias destacables.</li>';

    // Mapa de oraciones coloreado según el puntaje local.
    $('mapa').innerHTML = r.oraciones.map(function (o) {
      const a = Math.abs(o.puntaje) * 0.55;
      const color = o.nivel === 'ia' ? 'rgba(224,64,251,' + a + ')' : (o.nivel === 'humano' ? 'rgba(74,222,128,' + (a * 0.7) + ')' : 'transparent');
      // El color se aplica vía atributo data y luego por JS (la CSP bloquea estilos inline en HTML).
      return '<span data-c="' + color + '" title="Oración ' + (o.indice + 1) + ' · puntaje ' + o.puntaje.toFixed(2) + ' (' + o.nivel + ')">' + escapar(o.texto) + '</span> ';
    }).join('');
    $('mapa').querySelectorAll('[data-c]').forEach(function (s) { s.style.background = s.getAttribute('data-c'); });

    // Rasgos estilométricos con barra bipolar (izquierda humano, derecha IA).
    $('rasgos').innerHTML = r.rasgos.slice(0, 10).map(function (x) {
      return '<div class="rasgo"><div class="rasgo__fila"><span>' + escapar(x.nombre) + '</span><small>' + x.valor.toFixed(3) + ' · H ' + x.media_humano.toFixed(3) + ' / IA ' + x.media_ia.toFixed(3) + '</small></div>' +
        '<div class="bipolar" title="' + escapar(x.unidad) + '"><span class="bipolar__punto ' + x.direccion + '" data-p="' + x.escala + '"></span></div></div>';
    }).join('');
    requestAnimationFrame(function () { requestAnimationFrame(function () {
      $('rasgos').querySelectorAll('[data-p]').forEach(function (p) { p.style.left = (parseFloat(p.getAttribute('data-p')) * 100) + '%'; });
    }); });

    const chips = function (lista, clase) { return lista.map(function (t) { return '<span class="chip ' + clase + '" title="aporte ' + t.peso.toFixed(3) + '">' + escapar(t.termino) + '</span>'; }).join('') || '<span class="tenue pequeno">—</span>'; };
    $('ngIA').innerHTML = chips(r.ngramas.ia, 'ia');
    $('ngHum').innerHTML = chips(r.ngramas.humano, 'humano');
    $('marcadores').innerHTML = r.marcadores.map(function (m) { return '<span class="chip" title="' + escapar(m.descripcion) + '">' + escapar(m.frase) + ' ×' + m.ocurrencias + '</span>'; }).join('') || '<span class="tenue pequeno">Ninguno encontrado.</span>';

    $('proceso').innerHTML = r.proceso.map(function (p) { return '<li><b>' + escapar(p.paso) + ':</b> ' + escapar(p.detalle) + '</li>'; }).join('');

    // Tabla de calibración con las métricas medidas en el conjunto de prueba.
    const m = r.calibracion.metricas;
    const fila = function (nom, x) { return '<tr><td>' + nom + '</td><td>' + x.n + '</td><td>' + (x.fpr === null ? '—' : pct(x.fpr)) + '</td><td>' + (x.tpr === null ? '—' : pct(x.tpr)) + '</td><td>' + pct(x.nc) + '</td></tr>'; };
    const nombresLong = { len_25_60: '25–60 palabras', len_60_120: '60–120 palabras', len_120_250: '120–250 palabras', len_250_10000: '250+ palabras' };
    let filas = fila('Global', m.global) + fila('Español', m.es) + fila('Inglés', m.en);
    Object.keys(m.por_longitud).forEach(function (k) { filas += fila(nombresLong[k] || k, m.por_longitud[k]); });
    $('calibracion').innerHTML = '<p class="tenue pequeno">Medido sobre ' + m.global.n.toLocaleString('es') + ' textos de prueba que el modelo nunca vio. AUC = ' + m.auc.toFixed(3) + '. Precisión de familia = ' + pct(m.familia_acc) + '.</p>' +
      '<div class="tabla-scroll"><table><thead><tr><th>Conjunto</th><th>Textos</th><th>Humanos acusados (FP)</th><th>IA detectada</th><th>No concluyente</th></tr></thead><tbody>' + filas + '</tbody></table></div>';

    // En celular, desplaza la vista hasta el veredicto para que se vea sin buscarlo.
    if (window.innerWidth < 860) $('veredicto').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* Eventos. Ctrl/Cmd+Enter analiza desde el teclado (comodidad en escritorio). */
  el.analizar.addEventListener('click', analizar);
  el.limpiar.addEventListener('click', function () { el.entrada.value = ''; actualizarContador(); el.entrada.focus(); });
  el.entrada.addEventListener('input', actualizarContador);
  el.entrada.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') analizar(); });

  /* Service worker: cachea la app para uso offline cuando se sirve por HTTPS.
   * Se registra sólo si el navegador lo soporta y no es file:// (allí fallaría). */
  if ('serviceWorker' in navigator && location.protocol.indexOf('http') === 0) {
    navigator.serviceWorker.register('sw.js').catch(function () { /* Sin SW la app funciona igual, sólo que requiere conexión. */ });
  }

  detectarMotor();
  actualizarContador();
})();
