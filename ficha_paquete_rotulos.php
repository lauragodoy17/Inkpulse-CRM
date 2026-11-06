<?php
require_once("php/aut.php");
require_once("conexion/bdd.php");
require_once("includes/paquetes_colegio.php");

// Rótulos (stickers) de los paquetes de un colegio+periodo, para pegar en cada paquete armado.
// Sigue el formato de los stickers de las listas de empaque (lista_empaque_stickers.php en el
// repositorio de Eureka): mismo tamaño de sticker (477 × 672 px) pero en horizontal, fuente
// Inter y un rótulo por página.
//
// GET: colegio, periodo y cant[id_paquete] = cuántos rótulos de cada paquete (0 = ninguno).
// Sin cant[], sale uno de cada paquete (o solo del ?paquete= indicado).
if (!puede_ver_fichas_paquete()) {
    header("Location: index.php");
    exit;
}

crear_tablas_paquetes($bdd);

$id_colegio = (int)($_GET['colegio'] ?? 0);
$id_periodo = (int)($_GET['periodo'] ?? 0);
$id_paquete = (int)($_GET['paquete'] ?? 0);
$cant = is_array($_GET['cant'] ?? null) ? $_GET['cant'] : null;

$fichas = datos_fichas_paquetes($bdd, $id_colegio, $id_periodo, $id_paquete);

// Lista plana de rótulos a imprimir, en el orden de los grados. Tope de 500 por paquete para
// que un número mal digitado no cuelgue el navegador.
$rotulos = [];
if ($fichas) {
    foreach ($fichas['paquetes'] as $p) {
        $n = $cant === null ? 1 : max(0, min(500, (int)($cant[$p['id_paquete']] ?? 0)));
        for ($i = 1; $i <= $n; $i++) {
            $rotulos[] = ['p' => $p, 'i' => $i, 'n' => $n];
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Rótulos de paquetes</title>
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Inter', Arial, sans-serif; color: #111; background: #e5e7eb; }

    /* Barra de pantalla (no se imprime). */
    .rt-bar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 12px;
              padding: 12px 20px; background: #181f48; color: #fff; font-size: 14px; }
    .rt-bar strong { font-weight: 700; }
    .rt-bar button { border: 0; border-radius: 8px; padding: 9px 18px; background: #4361ee; color: #fff; font: 600 14px 'Inter', sans-serif; cursor: pointer; }
    .rt-hojas { display: flex; flex-wrap: wrap; justify-content: center; gap: 24px; padding: 24px; }
    .rt-vacio { padding: 60px 20px; text-align: center; color: #475569; }

    /* Hoja = una página de la impresora de stickers, en HORIZONTAL (pedido por la usuaria
       2026-11-06): 672 × 477 px, con 16px de margen a cada lado → área útil 640 × 477 px. */
    .rt-hoja { width: 640px; height: 477px; padding: 14px 0; background: #fff; box-shadow: 0 4px 16px rgba(0,0,0,.12); }
    .rt { height: 100%; border: 2px solid #111; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; }

    /* Solo el nombre del paquete y su contenido (pedido por la usuaria 2026-11-06): el resto se
       quitó para que la lista de libros salga lo más grande posible. */
    .rt-nombre { margin: 0; padding: 14px 18px; font-size: 22px; font-weight: 800; line-height: 1.2; text-align: center; border-bottom: 2px solid #111; }

    .rt-libros { flex: 1; padding: 12px 18px; overflow: hidden; }
    .rt-libros-tit { display: flex; justify-content: space-between; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
                     padding-bottom: 6px; border-bottom: 1px dashed #111; margin-bottom: 6px; }
    .rt-libro { display: grid; grid-template-columns: 26px 1fr auto; gap: 8px; align-items: baseline; padding: 6px 0; font-size: 16px; font-weight: 600; line-height: 1.25; }
    .rt-libro b { font-weight: 800; }
    .rt-libro em { font-style: normal; font-size: 13px; font-weight: 500; white-space: nowrap; }
    /* Pocos títulos: letra más grande para aprovechar el rótulo. */
    .rt-holgado .rt-libro { font-size: 19px; padding: 7px 0; }
    .rt-holgado .rt-libro em { font-size: 14px; }
    /* Paquetes con muchos títulos: letra más pequeña para que quepan en el rótulo. */
    /* En horizontal sobra ancho y falta alto: con muchos títulos la lista va en dos columnas. */
    /* Cuadrícula llenada por columnas (1…k a la izquierda, k+1…n a la derecha); el número de filas
       lo pone el PHP. Con column-count los últimos títulos quedaban ocultos en una 3.ª columna. */
    .rt-compacto .rt-lista { display: grid; grid-auto-flow: column; grid-template-columns: 1fr 1fr; column-gap: 22px; align-content: start; }
    .rt-compacto .rt-libro { grid-template-columns: 22px 1fr auto; gap: 5px; font-size: 12px; line-height: 1.2; padding: 2px 0; }
    .rt-compacto .rt-libro em { font-size: 10px; }

    @media print {
      @page { size: 672px 477px; margin: 0 16px; }
      body { background: #fff; }
      .rt-bar { display: none; }
      .rt-hojas { display: block; padding: 0; }
      /* Unos px menos que la página: si la hoja mide exactamente lo mismo, el redondeo de la
         impresora puede pasar 1px a la siguiente etiqueta y sacar un rótulo de más. */
      .rt-hoja { height: 465px; overflow: hidden; box-shadow: none; page-break-inside: avoid; break-inside: avoid; page-break-after: always; break-after: page; }
      .rt-hoja:last-child { page-break-after: auto; break-after: auto; }
    }
  </style>
</head>
<body>

<?php if (!$fichas || !$rotulos): ?>
  <div class="rt-vacio">
    <?= !$fichas ? 'No hay fichas técnicas para este colegio y periodo (el Tipo de adopción debe ser "Paquetes" o "Ambos").' : 'No se indicó ningún rótulo para imprimir.' ?>
  </div>
<?php else: ?>
  <div class="rt-bar">
    <span><strong><?= count($rotulos) ?></strong> rótulo<?= count($rotulos) === 1 ? '' : 's' ?> · <?= h($fichas['colegio']) ?> · Periodo <?= h($fichas['periodo']) ?></span>
    <button type="button" onclick="window.print()">Imprimir</button>
  </div>

  <div class="rt-hojas">
    <?php foreach ($rotulos as $r): $p = $r['p']; $nl = count($p['libros']); ?>
    <div class="rt-hoja">
      <div class="rt">
        <h1 class="rt-nombre"><?= h($p['nombre']) ?></h1>

        <div class="rt-libros<?= $nl > 8 ? ' rt-compacto' : ($nl <= 6 ? ' rt-holgado' : '') ?>">
          <div class="rt-libros-tit"><span>Contenido</span><span>ISBN</span></div>
          <div class="rt-lista" style="grid-template-rows: repeat(<?= (int)ceil($nl / 2) ?>, auto);">
          <?php foreach ($p['libros'] as $k => $l): ?>
          <div class="rt-libro"><b><?= $k + 1 ?>.</b><span><?= h($l['libro']) ?></span><em><?= $l['isbn'] !== '' ? h($l['isbn']) : '—' ?></em></div>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</body>
</html>
