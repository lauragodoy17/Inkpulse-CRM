/**
 * /src/contador-caracteres.js
 * Contador de caracteres para campos de texto con límite: a todo <textarea> o <input> con
 * `maxlength` y el atributo `data-contador` le agrega debajo "N / máx caracteres", que cambia de
 * color al acercarse al límite. El límite en sí lo pone `maxlength` (el navegador no deja escribir
 * ni pegar más).
 *
 * Si el campo ya trae un texto guardado más largo que el límite (observaciones anteriores a la
 * regla), no se recorta: se avisa en rojo. El navegador no deja agregar más y, si se edita, pide
 * dejarlo dentro del límite para poder guardar.
 *
 * Uso: <textarea name="observaciones" maxlength="300" data-contador></textarea>
 *      <script src="src/contador-caracteres.js"></script>
 */
(function () {
  var css = '.cc-contador{font-size:.74rem;color:#64748b;text-align:right;margin-top:4px;line-height:1.3;}' +
            '.cc-contador.cc-cerca{color:#b45309;font-weight:600;}' +
            '.cc-contador.cc-limite{color:#dc2626;font-weight:700;}';
  var s = document.createElement('style');
  s.textContent = css;
  document.head.appendChild(s);

  function actualizar(campo, contador) {
    var max = parseInt(campo.getAttribute('maxlength'), 10);
    var largo = campo.value.length;
    contador.classList.remove('cc-cerca', 'cc-limite');
    if (largo > max) {
      contador.classList.add('cc-limite');
      contador.textContent = largo + ' / ' + max + ' caracteres — el texto guardado supera el límite de ' + max +
        '; para modificarlo debes dejarlo en ' + max + ' o menos.';
    } else if (largo === max) {
      contador.classList.add('cc-limite');
      contador.textContent = largo + ' / ' + max + ' caracteres — llegaste al límite.';
    } else {
      if (largo >= max * 0.9) contador.classList.add('cc-cerca');
      contador.textContent = largo + ' / ' + max + ' caracteres';
    }
  }

  function iniciar() {
    var campos = document.querySelectorAll('textarea[data-contador][maxlength], input[data-contador][maxlength]');
    Array.prototype.forEach.call(campos, function (campo) {
      if (campo._ccContador) return;
      var contador = document.createElement('div');
      contador.className = 'cc-contador';
      contador.setAttribute('aria-live', 'polite');
      campo.parentNode.insertBefore(contador, campo.nextSibling);
      campo._ccContador = contador;
      ['input', 'change', 'keyup'].forEach(function (ev) { campo.addEventListener(ev, function () { actualizar(campo, contador); }); });
      actualizar(campo, contador);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
