<?php

// php ejemplos/emitir_boleta.php   (pon tus datos reales en las variables de entorno)

require __DIR__ . '/../vendor/autoload.php';

use FactuSmart\Cliente;
use FactuSmart\Excepciones\FactuSmartError;

$fs = new Cliente([
    'apiKey' => getenv('FACTUSMART_API_KEY'),
    'baseUrl' => getenv('FACTUSMART_BASE_URL'),   // ej. https://s2.factusmart.pe/api/v1/issuer
    'ruc' => getenv('FACTUSMART_RUC'),
]);

['items' => $items, 'totales' => $totales] = Cliente::calcularComprobante([
    ['codigo_interno' => 'P-001', 'descripcion' => 'Producto de prueba', 'cantidad' => 1, 'valor_unitario' => 50, 'afectacion' => '10'],
]);

try {
    $r = $fs->comprobantes->emitir([
        'codigo_tipo_documento' => '03',
        'datos_del_cliente_o_receptor' => ['codigo_tipo_documento_identidad' => '1', 'numero_documento' => '12345678'],
        'items' => $items,
        'totales' => $totales,
    ], ['idempotencyKey' => 'venta-123']);   // el id de la venta en TU sistema

    if ($r['aceptado_por_sunat']) {
        echo "Aceptado: {$r['serie_numero']} ({$r['entorno']})\n";
        file_put_contents("{$r['serie_numero']}.pdf", $fs->comprobantes->descargar($r['external_id'], 'pdf'));
    } else {
        echo "Generado pero NO aceptado ({$r['estado']}): " . ($r['accion_requerida'] ?? '') . "\n";
    }
} catch (FactuSmartError $e) {
    echo get_class($e) . " ({$e->status}): {$e->mensaje}\n";
}
