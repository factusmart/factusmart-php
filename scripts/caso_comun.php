<?php

// Genera resultado_caso_comun.json con el caso de prueba común de DISENO_SDK.md,
// para comparar al centavo con los SDK de Node y Python.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FactuSmart\Cliente;

$entrada = require __DIR__ . '/../tests/fixtures/caso_comun_entrada.php';
$resultado = Cliente::calcularComprobante($entrada);

$json = json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents(__DIR__ . '/../resultado_caso_comun.json', $json);
echo $json;
