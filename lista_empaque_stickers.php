<?php
  require_once("php/aut.php");
  require_once("conexion/bdd.php");

?>
<head>
  <meta charset="utf-8" />
  <title>Inkpulse - Listas de empaque Stickers</title>
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

  <style>
    .stickers {
      width: 520;
      height: 455px;
      text-align:center;
      font-size: 27px;
      font-weight: 600;
      margin: 0;
      font-family: 'Inter';
            
    }
    .cqr {
      font-size: 20px;
    }

    .conse {
      font-size: 9px;
    }

    @media print 
        {
              @page
            {
              size: 477px 672;
              margin: 0px 5px 0px 31px
              
            }
        }
      .negrita {
        font-weight: 1000 !important;
      }
      </style>
</head>
<body>
  <?php

    $stmt = $bdd->prepare(
      "SELECT id, empresa,cliente, telefono_recibe, ciudad, direccion, persona_recibe, total_cajas
      FROM listas_empaque 
      WHERE id=?"
      );
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $lista = $stmt->fetch();
    if (!$lista) { echo "<p>Lista de empaque no encontrada.</p>"; $lista = ['total_cajas' => 0]; }
    $lista = array_map(function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }, $lista);

    for ($i = 1; $i <= $lista['total_cajas']; $i++) {

      echo "<div class='stickers'>
        <div style='border: 1px solid black; margin-bottom: 30px; -moz-border-radius: 12px; -webkit-border-radius: 12px; padding: 5px;'>
          <h3 style='margin-bottom: 10px;' >".$lista["persona_recibe"]."</h3>

            <h3>".$lista["ciudad"]."</h3>
            <h3 style='margin-bottom: 10px;'>".$lista["direccion"]."</h3><br>
            <h4 style='margin-bottom: 10px;'>".$lista["telefono_recibe"]."</h4>
            
            <p style='font-size:15px'>CAJA ".$i."/".$lista["total_cajas"]."</p>
            <p style='font-size:12px'>".$lista["empresa"]."</p>
        </div>
      </div>";
    }
?>
</body>
