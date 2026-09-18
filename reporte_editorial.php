<?php
require_once("php/aut.php");

$tipo_sesion = intval($_SESSION["tipo"] ?? 0);
if (!in_array($tipo_sesion, [1, 2], true)) {
    header("Location: index.php");
    exit;
}

require_once("conexion/bdd.php");
require_once("includes/periodos_fechas.php");
require_once("includes/informe_editorial_datos.php");

asegurar_fechas_periodos($bdd);
$infoPeriodos = obtener_periodo_activo($bdd);
$idPeriodo = isset($_GET['periodo']) && $_GET['periodo'] !== '' ? (int)$_GET['periodo'] : $infoPeriodos['periodoActivo'];

$ultimo = obtener_ultimo_snapshot_informe_editorial($bdd, $idPeriodo);
$fechaUltimo = $ultimo['fecha'] ? date('d/m/Y', strtotime($ultimo['fecha'])) : null;

// Asesores de la temporada seleccionada + lo que ya se haya guardado de "Presupuesto asignado por
// temporada" (columna J del Excel), para el formulario de abajo — pedido por el usuario
// 2026-09-18 para no tener que volver a escribirlo cada semana.
$asesoresTemporada = obtener_datos_informe_editorial($bdd, $idPeriodo)['asesores'];
$presupuestoGuardado = obtener_presupuesto_temporada_informe_editorial($bdd, $idPeriodo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Informe Cumplimiento</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css" />
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css" />
  <style>
    .ie-section { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.08); padding: 24px; }
    .ie-aviso {
      background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a;
      border-radius: 10px; padding: 12px 16px; font-size: .85rem; margin: 0 0 18px;
    }
    .ie-btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 26px; border-radius: 8px; font-size: .95rem; font-weight: 700;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: #fff; border: none; cursor: pointer; text-decoration: none;
    }
    .ie-btn:hover { opacity: .9; color: #fff; text-decoration: none; }
    .ie-section + .ie-section { margin-top: 20px; }
    table.ie-tabla { width: 100%; border-collapse: collapse; font-size: .87rem; }
    table.ie-tabla th, table.ie-tabla td { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: left; }
    table.ie-tabla thead th { background: #eef2ff; color: #1e3a8a; font-weight: 700; }
    table.ie-tabla td.ie-num { text-align: right; }
    table.ie-tabla input[type=text] {
      width: 100%; text-align: right; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; font-size: .87rem;
    }
    .ie-vacio { color: #64748b; font-size: .85rem; padding: 16px 0; text-align: center; }
  </style>
</head>
<body>

<?php include("template/nav_side.php"); ?>
<div class="main-container">
  <div class="pd-ltr-20 xs-pd-20-10">
    <div class="min-height-200px">

      <div class="page-header">
        <div class="row align-items-center">
          <div class="col-md-8 col-sm-12">
            <div class="title"><h4>Informe Cumplimiento</h4></div>
          </div>
        </div>
      </div>

      <div class="ie-section">
        <p class="ie-aviso">
          Presupuesto asignado y adopción por asesor (Eureka, McGraw Hill y Otra editorial), con el
          % de cumplimiento frente a la meta de 100%. Los datos de "hoy" se calculan al momento de
          descargar; la columna "Último informe" del Excel se actualiza sola todos los <strong>viernes</strong>
          a las 9:00 am (el informe se saca semanalmente, así que compara contra el viernes
          anterior)<?= $fechaUltimo ? ", el más reciente guardado es del $fechaUltimo" : " — todavía no hay ningún informe guardado para comparar" ?>.
        </p>
        <form action="php/informe_editorial_excel.php" method="POST">
          <div class="row">
            <div class="col-md-3 col-12 mb-3">
              <label class="control-label">Período</label>
              <!-- Al cambiar recarga la página (ver JS abajo) para refrescar también la tabla de
                   presupuesto por temporada con la de este período/temporada nuevo. -->
              <select name="periodo" id="ie-periodo" class="form-control">
                <?php foreach ($infoPeriodos['periodos'] as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$idPeriodo ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['periodo']) ?> (Calendario <?= htmlspecialchars($p['calendario']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="ie-btn"><i class="bi bi-file-earmark-excel"></i> Descargar Excel</button>
        </form>
      </div>

      <div class="ie-section">
        <p class="ie-aviso">
          Presupuesto objetivo de la temporada por asesor (columna "Presupuesto asignado por
          temporada" del Excel) — se guarda aquí para que no tengas que volver a escribirlo cada
          semana; el próximo Excel que descargues ya lo trae precargado. Dejar un campo vacío borra
          lo guardado para ese asesor (la columna vuelve a calcularse contra el presupuesto normal
          del CRM).
        </p>
        <?php if (empty($asesoresTemporada)): ?>
          <div class="ie-vacio">No hay asesores con presupuesto/adopción registrada en esta temporada todavía.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="ie-tabla" id="ie-tabla-presupuesto">
              <thead>
                <tr><th>Asesor</th><th style="width:260px;">Presupuesto asignado por temporada</th></tr>
              </thead>
              <tbody>
                <?php foreach ($asesoresTemporada as $a): ?>
                  <tr>
                    <td><?= htmlspecialchars($a['nombre']) ?><?= $a['activo'] ? '' : ' <span style="color:#c0392b;">(inactivo)</span>' ?></td>
                    <td>
                      <input type="text" inputmode="decimal" class="ie-input-presupuesto"
                             data-id-usuario="<?= (int)$a['id_usuario'] ?>"
                             value="<?= isset($presupuestoGuardado[$a['id_usuario']]) ? (int)$presupuestoGuardado[$a['id_usuario']] : '' ?>"
                             placeholder="0">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:16px;">
            <button type="button" class="ie-btn" id="ie-btn-guardar-presupuesto"><i class="bi bi-save"></i> Guardar presupuesto por temporada</button>
          </div>
        <?php endif; ?>
      </div>

    </div>
    <?php include("template/footer.php"); ?>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script>
(function () {
  document.getElementById('ie-periodo').addEventListener('change', function () {
    window.location = '?periodo=' + encodeURIComponent(this.value);
  });

  var btnGuardar = document.getElementById('ie-btn-guardar-presupuesto');
  if (btnGuardar) {
    btnGuardar.addEventListener('click', function () {
      var idPeriodo = document.getElementById('ie-periodo').value;
      var inputs = document.querySelectorAll('.ie-input-presupuesto');
      var datos = new URLSearchParams();
      datos.append('periodo', idPeriodo);
      inputs.forEach(function (input) {
        datos.append('presupuesto[' + input.dataset.idUsuario + ']', input.value.trim());
      });

      btnGuardar.disabled = true;
      fetch('php/informe_editorial_guardar_presupuesto_temporada.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: datos.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          btnGuardar.disabled = false;
          if (resp && resp.success) {
            if (window.inkToast) window.inkToast('Presupuesto por temporada guardado.', 'success');
          } else {
            if (window.inkToast) window.inkToast((resp && resp.message) || 'No se pudo guardar.', 'error');
          }
        })
        .catch(function () {
          btnGuardar.disabled = false;
          if (window.inkToast) window.inkToast('Error de red al guardar.', 'error');
        });
    });
  }
})();
</script>
<script src="src/ink-alerts.js"></script>
</body>
</html>
