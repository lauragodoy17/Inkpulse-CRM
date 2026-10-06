<?php
/**
 * Libros que al pedirse en un pedido sin adopción llevan además su cartilla: [id_libro => id_cartilla].
 * Lo usan php/pedido_sa.php (solicitar pedido sin adopción) y el módulo Backorders sin adopción
 * (includes/backorders_sa_datos.php), para que ambos armen el pedido igual.
 */
function relaciones_cartillas_pedido_sa() {
	return [
		4455 => 4083,
		4458 => 4084,
		4459 => 4085,
		4460 => 4086,
		4461 => 4087,
		4462 => 4088,
		4463 => 4089,
		4464 => 4090,
		4465 => 4091,
		4456 => 4092,
		4457 => 4093,
		4444 => 4660,
		4447 => 4659,
		4448 => 4658,
		4449 => 4657,
		4450 => 4656,
		4451 => 4637,
		4452 => 4638,
		4453 => 4639,
		4454 => 4652,
		4445 => 4651,
		4446 => 4650,
		4435 => 5038,
		4436 => 5039,
		4437 => 5040,
		4438 => 4800,
		4439 => 4632,
		4440 => 4633,
		4441 => 4634,
		4442 => 4635,
		4443 => 4636,
		4466 => 4797,
		4467 => 4623,
		4468 => 4624,
		4469 => 4625,
		4470 => 4626,
		4471 => 4627,
		4472 => 4628,
		4473 => 4629,
		4474 => 4630,
	];
}
