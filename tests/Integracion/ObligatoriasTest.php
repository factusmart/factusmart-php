<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Integracion;

use FactuSmart\Cliente;
use FactuSmart\Codigos;
use FactuSmart\Excepciones\ErrorDeAutenticacion;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\NoEncontrado;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Las 9 pruebas obligatorias de DISENO_SDK.md, contra el API LOCAL (Laragon).
 * Emite 3 comprobantes por corrida (P3, P5 y P5b); en RUCs demo no cobran crédito pero cuentan
 * para el tope de 200 operaciones/día por cuenta. Nunca apuntar a producción.
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
                'numero_documento' => '12345678',
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

    public function testP5IdempotenciaMismaClaveMismoComprobanteSinCobrarDosVeces(): void
    {
        $fs = self::cliente();
        $clave = 'sdk-php-' . bin2hex(random_bytes(8));

        $r1 = $fs->comprobantes->emitir(self::boleta(), ['idempotencyKey' => $clave]);
        $r2 = $fs->comprobantes->emitir(self::boleta(), ['idempotencyKey' => $clave]);

        $this->assertSame($r1['external_id'], $r2['external_id']);
        $this->assertSame($r1['serie_numero'], $r2['serie_numero']);
        // En RUCs demo no se cobra crédito: el saldo no baja, así que no se mira el saldo.
        if (array_key_exists('credito_cobrado', $r2)) {
            $this->assertFalse($r2['credito_cobrado'], 'La segunda llamada con la misma clave no debe cobrar');
        }
        self::log(sprintf(
            '[P5] %s pedido dos veces con la misma clave; credito_cobrado %s -> %s',
            $r1['serie_numero'],
            var_export($r1['credito_cobrado'] ?? null, true),
            var_export($r2['credito_cobrado'] ?? null, true)
        ));
    }

    /** @return array<string, mixed> */
    public function testP5bCalcularEnElServidorDaElCasoComun(): array
    {
        $r = self::cliente()->comprobantes->calcular(require __DIR__ . '/../fixtures/caso_comun_entrada.php');
        $this->assertTrue($r['success']);
        $esperado = json_decode((string) file_get_contents(__DIR__ . '/../../resultado_caso_comun.json'), true);
        // Comparación numérica (el JSON puede traer 10 o 10.0): assertEquals, no assertSame.
        $this->assertEquals($esperado['items'], $r['items']);
        $this->assertEquals($esperado['totales'], $r['totales']);
        self::log("[P5b] /calcular = resultado_caso_comun.json (total_venta {$r['totales']['total_venta']})");

        return self::cliente()->comprobantes->calcular([
            ['codigo_interno' => 'SDK-PHP-002', 'descripcion' => 'Menu SDK PHP (precio con IGV)', 'cantidad' => 3, 'precio_unitario' => 10, 'afectacion' => '10'],
        ]);
    }

    /** @param array<string, mixed> $calculo */
    #[Depends('testP5bCalcularEnElServidorDaElCasoComun')]
    public function testP5bEmitirBoletaConLoQueDevuelveCalcular(array $calculo): void
    {
        $this->assertEquals(30, $calculo['totales']['total_venta'], '3 × S/ 10.00 con IGV = S/ 30.00');
        $datos = self::boleta();
        $datos['items'] = $calculo['items'];
        $datos['totales'] = $calculo['totales'];

        $r = self::cliente()->comprobantes->emitir($datos);
        $this->assertTrue($r['success']);
        $this->assertTrue($r['aceptado_por_sunat'], 'SUNAT beta no aceptó: ' . json_encode($r['sunat'] ?? null, JSON_UNESCAPED_UNICODE));
        self::log("[P5b] emitida {$r['serie_numero']} con items/totales de /calcular (total 30.00)");
    }

    public function testP5cCatalogosListarYConsultar07(): void
    {
        $fs = self::cliente();
        $l = $fs->catalogos->listar();
        $this->assertTrue($l['success']);
        $this->assertNotEmpty($l['catalogos']);

        $c = $fs->catalogos->consultar('07');
        $this->assertTrue($c['success']);
        $codigos = array_column($c['datos'], 'codigo');
        $this->assertContains('10', $codigos, 'El catálogo 07 debe traer la fila codigo 10');
        self::log('[P5c] ' . count($l['catalogos']) . " catálogos; 07 = {$c['catalogo']} con {$c['total']} filas");
    }

    public function testP6ApiKeyFalsaLanzaErrorDeAutenticacion(): void
    {
        try {
            self::cliente('llave_falsa_' . bin2hex(random_bytes(4)))->ping();
            $this->fail('Debió lanzar ErrorDeAutenticacion');
        } catch (ErrorDeAutenticacion $e) {
            $this->assertSame(401, $e->status);
            $this->assertNotSame('', $e->mensaje);
            $this->assertSame(Codigos::NO_AUTENTICADO, $e->codigo);
            $this->assertFalse($e->reintentable);
            self::log("[P6] {$e->status} {$e->codigo}: {$e->mensaje}");
        }
    }

    public function testP6bDescargaDeComprobanteInexistenteEsNoEncontradoConCodigo(): void
    {
        try {
            // UUID con formato válido que no existe: la ruta casa, el comprobante no.
            self::cliente()->comprobantes->descargar('00000000-0000-4000-8000-' . bin2hex(random_bytes(6)), 'pdf');
            $this->fail('Debió lanzar NoEncontrado');
        } catch (NoEncontrado $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame(Codigos::NO_ENCONTRADO, $e->codigo);
            $this->assertFalse($e->reintentable);
            $this->assertStringNotContainsString('<html', $e->mensaje);
            self::log("[P6b] {$e->status} {$e->codigo}: {$e->mensaje}");
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
            $this->assertSame(Codigos::DATOS_INVALIDOS, $e->codigo);
            $this->assertFalse($e->reintentable);
            self::log("[P7] {$e->status} {$e->codigo}: {$e->mensaje}");
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
