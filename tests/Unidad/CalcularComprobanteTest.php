<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Excepciones\FactuSmartError;
use PHPUnit\Framework\TestCase;

final class CalcularComprobanteTest extends TestCase
{
    public function testCasoComunDelDiseno(): void
    {
        $entrada = require __DIR__ . '/../fixtures/caso_comun_entrada.php';
        $r = Cliente::calcularComprobante($entrada);

        $this->assertSame([
            'total_operaciones_gravadas' => 31.67,
            'total_operaciones_exoneradas' => 10.0,
            'total_operaciones_inafectas' => 7.1,
            'total_exportacion' => 100.0,
            'total_igv' => 5.7,
            'total_impuestos' => 5.7,
            'total_valor' => 148.77,
            'total_venta' => 154.47,
        ], $r['totales']);

        $a = $r['items'][0];
        $this->assertSame(['descripcion', 'unidad_de_medida', 'cantidad', 'valor_unitario',
            'codigo_tipo_precio', 'precio_unitario', 'codigo_tipo_afectacion_igv', 'total_base_igv', 'porcentaje_igv',
            'total_igv', 'total_impuestos', 'total_valor_item', 'total_item'], array_keys($a));
        $this->assertSame(10.56, $a['valor_unitario']);
        $this->assertSame(12.45, $a['precio_unitario']);
        $this->assertSame(31.67, $a['total_base_igv']);
        $this->assertSame(5.7, $a['total_igv']);
        $this->assertSame(37.37, $a['total_item']);
        $this->assertSame(18, $a['porcentaje_igv']);
        $this->assertSame('NIU', $a['unidad_de_medida']);
        $this->assertSame('01', $a['codigo_tipo_precio']);

        $d = $r['items'][3];
        $this->assertSame('40', $d['codigo_tipo_afectacion_igv']);
        $this->assertSame(0, $d['porcentaje_igv']);
        $this->assertSame(0.0, $d['total_igv']);

        // El archivo entregado coincide con lo que calcula el SDK hoy.
        $archivo = __DIR__ . '/../../resultado_caso_comun.json';
        $this->assertFileExists($archivo);
        $this->assertEquals(json_decode((string) json_encode($r), true), json_decode((string) file_get_contents($archivo), true));
    }

    public function testEjemploDeLaDocPublica(): void
    {
        $r = Cliente::calcularComprobante([
            ['codigo_interno' => 'PROD-001', 'descripcion' => 'Servicio de consultoría', 'cantidad' => 1, 'valor_unitario' => 100, 'afectacion' => '10', 'unidad_de_medida' => 'ZZ'],
        ]);
        $this->assertSame(118.0, $r['totales']['total_venta']);
        $this->assertSame(18.0, $r['totales']['total_igv']);
        $this->assertSame(118.0, $r['items'][0]['precio_unitario']);
        $this->assertSame('PROD-001', $r['items'][0]['codigo_interno']);
        $this->assertSame('ZZ', $r['items'][0]['unidad_de_medida']);
    }

    public function testRedondeoSinRuidoDeComaFlotante(): void
    {
        // 1.005 * 1 en binario es 1.00499999...; debe redondear a 1.01 (mitad hacia arriba).
        $r = Cliente::calcularComprobante([['descripcion' => 'x', 'cantidad' => 1, 'valor_unitario' => 1.005, 'afectacion' => '20']]);
        $this->assertSame(1.01, $r['totales']['total_venta']);

        // Más casos donde el float puro falla: 2.675 → 2.68, 1.115 → 1.12, 0.125 → 0.13.
        foreach ([[2.675, 2.68], [1.115, 1.12], [0.125, 0.13], [8.345, 8.35]] as [$valor, $esperado]) {
            $r = Cliente::calcularComprobante([['descripcion' => 'x', 'cantidad' => 1, 'valor_unitario' => $valor, 'afectacion' => '30']]);
            $this->assertSame($esperado, $r['items'][0]['valor_unitario'], "r2({$valor})");
        }
    }

    public function testAfectacionNoSoportada(): void
    {
        $this->expectException(FactuSmartError::class);
        $this->expectExceptionMessage('afectación "11"');
        Cliente::calcularComprobante([['descripcion' => 'x', 'cantidad' => 1, 'valor_unitario' => 1, 'afectacion' => '11']]);
    }

    public function testItemIncompleto(): void
    {
        $this->expectException(FactuSmartError::class);
        $this->expectExceptionMessage('le falta "valor_unitario"');
        Cliente::calcularComprobante([['descripcion' => 'x', 'cantidad' => 1, 'afectacion' => '10']]);
    }
}
