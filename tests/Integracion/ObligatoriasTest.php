<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Integracion;

use FactuSmart\Cliente;
use FactuSmart\Excepciones\ErrorDeAutenticacion;
use FactuSmart\Excepciones\ErrorDeValidacion;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Las 9 pruebas obligatorias de DISENO_SDK.md, contra el API LOCAL (Laragon).
 * Gasta 2 créditos por corrida (P3 y P5). Nunca apuntar a producción.
 *
 * Variables: FACTUSMART_BASE_URL, FACTUSMART_API_KEY, FACTUSMART_RUC, FACTUSMART_VERIFICAR_SSL (ver phpunit.xml).
 */
final class ObligatoriasTest extends TestCase
{
    private static function cliente(?string $apiKey = null): Cliente
    {
        $base = (string) getenv('FACTUSMART_BASE_URL');
        if (!preg_match('#^https?://[^/]*(\.test|localhost|127\.0\.0\.1)[:/]#i', $base)) {
            self::fail("Estas pruebas solo corren contra un API local; FACTUSMART_BASE_URL={$base}");
        }
        return new Cliente([
            'apiKey' => $apiKey ?? (string) getenv('FACTUSMART_API_KEY'),
            'baseUrl' => $base,
            'ruc' => (string) getenv('FACTUSMART_RUC'),
            'verificarSsl' => getenv('FACTUSMART_VERIFICAR_SSL') !== '0',
        ]);
    }

    /** @return array<string, mixed> */
    private static function boleta(): array
    {
        $calculo = Cliente::calcularComprobante([
            ['codigo_interno' => 'SDK-PHP-001', 'descripcion' => 'Prueba SDK PHP', 'cantidad' => 2, 'valor_unitario' => 12.5, 'afectacion' => '10'],
        ]);
        return [
            'codigo_tipo_documento' => '03',
            'datos_del_cliente_o_receptor' => [
                'codigo_tipo_documento_identidad' => '1',
                'numero_documento' => '77344962',
                'apellidos_y_nombres_o_razon_social' => 'CLIENTE PRUEBA SDK PHP',
            ],
            'items' => $calculo['items'],
            'totales' => $calculo['totales'],
        ];
    }

    private static function log(string $texto): void
    {
        fwrite(STDERR, "\n  " . $texto . "\n");
    }

    public function testP1PingResponde(): void
    {
        $r = self::cliente()->ping();
        $this->assertTrue($r['success']);
        $this->assertSame(getenv('FACTUSMART_RUC'), $r['ruc_emisor']);
        $this->assertIsInt($r['creditos']);
    }

    public function testP2SaldoTraeCreditosDisponibles(): void
    {
        $r = self::cliente()->cuenta->saldo();
        $this->assertArrayHasKey('creditos_disponibles', $r);
        $this->assertIsInt($r['creditos_disponibles']);
    }

    /** @return array<string, mixed> */
    public function testP3EmitirBoletaConCalcularComprobante(): array
    {
        $r = self::cliente()->comprobantes->emitir(self::boleta());
        $this->assertTrue($r['success']);
        $this->assertTrue($r['aceptado_por_sunat'], 'SUNAT beta no aceptó: ' . json_encode($r['sunat'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertSame('prueba', $r['entorno']);
        $this->assertFalse($r['tiene_validez_fiscal']);
        self::log("[P3] emitida {$r['serie_numero']} external_id={$r['external_id']} estado={$r['estado']}");
        return $r;
    }

    /** @param array<string, mixed> $emitida */
    #[Depends('testP3EmitirBoletaConCalcularComprobante')]
    public function testP4ConsultarHistorialYDescargarPdf(array $emitida): void
    {
        $fs = self::cliente();
        $id = $emitida['external_id'];

        $c = $fs->comprobantes->consultar($id);
        $this->assertSame($id, $c['external_id']);
        $this->assertSame($emitida['serie_numero'], $c['serie_numero']);
        $this->assertArrayHasKey('pdf', $c['links']);

        $h = $fs->comprobantes->historial($id);
        $this->assertSame($id, $h['external_id']);
        $this->assertNotEmpty($h['historial']);

        $pdf = $fs->comprobantes->descargar($id, 'pdf');
        $this->assertStringStartsWith('%PDF', $pdf);
        self::log('[P4] PDF de ' . strlen($pdf) . ' bytes, historial con ' . count($h['historial']) . ' eventos');
    }

    public function testP5IdempotenciaMismaClaveMismoComprobanteYUnSoloCredito(): void
    {
        $fs = self::cliente();
        $clave = 'sdk-php-' . bin2hex(random_bytes(8));

        $antes = $fs->cuenta->saldo()['creditos_disponibles'];
        $r1 = $fs->comprobantes->emitir(self::boleta(), ['idempotencyKey' => $clave]);
        $r2 = $fs->comprobantes->emitir(self::boleta(), ['idempotencyKey' => $clave]);
        $despues = $fs->cuenta->saldo()['creditos_disponibles'];

        $this->assertSame($r1['external_id'], $r2['external_id']);
        $this->assertSame($r1['serie_numero'], $r2['serie_numero']);
        $this->assertSame(1, $antes - $despues, "El saldo debió bajar 1 (antes {$antes}, después {$despues})");
        self::log("[P5] {$r1['serie_numero']} pedido dos veces con la misma clave, saldo {$antes} -> {$despues}");
    }

    public function testP6ApiKeyFalsaLanzaErrorDeAutenticacion(): void
    {
        try {
            self::cliente('llave_falsa_' . bin2hex(random_bytes(4)))->ping();
            $this->fail('Debió lanzar ErrorDeAutenticacion');
        } catch (ErrorDeAutenticacion $e) {
            $this->assertSame(401, $e->status);
            $this->assertNotSame('', $e->mensaje);
            self::log("[P6] {$e->status}: {$e->mensaje}");
        }
    }

    public function testP7PayloadInvalidoLanzaErrorDeValidacionLegible(): void
    {
        try {
            // Sin cliente, sin ítems ni totales.
            self::cliente()->comprobantes->emitir(['codigo_tipo_documento' => '03']);
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(422, $e->status);
            $this->assertGreaterThan(10, mb_strlen($e->mensaje));
            $this->assertDoesNotMatchRegularExpression('/^Array|^\{/', $e->mensaje);
            self::log("[P7] {$e->status}: {$e->mensaje}");
        }
    }

    public function testP8ContratoTodosLosOperationIdTienenMetodo(): void
    {
        $ruta = (string) getenv('FACTUSMART_OPENAPI');
        $yaml = (string) file_get_contents(is_file($ruta) ? $ruta : __DIR__ . '/../fixtures/openapi.yaml');
        preg_match_all('/^\s*operationId:\s*[\'"]?([A-Za-z0-9_]+)/m', $yaml, $m);
        $this->assertGreaterThanOrEqual(17, count($m[1]));
        $fs = self::cliente();
        foreach ($m[1] as $id) {
            $this->assertArrayHasKey($id, Cliente::OPERACIONES, "Falta {$id}");
            [$recurso, $metodo] = Cliente::OPERACIONES[$id];
            $this->assertTrue(method_exists($recurso === null ? $fs : $fs->{$recurso}, $metodo), "Falta método para {$id}");
        }
    }

    public function testP9CalcularComprobanteCasoComun(): void
    {
        $r = Cliente::calcularComprobante(require __DIR__ . '/../fixtures/caso_comun_entrada.php');
        $this->assertSame(154.47, $r['totales']['total_venta']);
        $this->assertSame(5.7, $r['totales']['total_igv']);
        $this->assertSame(100.0, $r['totales']['total_exportacion']);
        $this->assertEquals(
            json_decode((string) json_encode($r), true),
            json_decode((string) file_get_contents(__DIR__ . '/../../resultado_caso_comun.json'), true)
        );
    }
}
