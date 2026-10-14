<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Codigos;
use FactuSmart\Excepciones\ErrorDeAutenticacion;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Excepciones\LimiteDePeticiones;
use FactuSmart\Excepciones\NoEncontrado;
use FactuSmart\Http\Respuesta;
use PHPUnit\Framework\TestCase;

/** Pruebas sin red de 0.2.0: codigo / reintentable / campos y firma de webhooks. */
final class CodigosYWebhooksTest extends TestCase
{
    /** Vector común a los SDK de Node, Python y PHP. */
    private const VECTOR_CUERPO = '{"evento":"comprobante.aceptado"}';
    private const VECTOR_SECRETO = 'whsec_prueba';
    private const VECTOR_FIRMA = 'bc1542f6420851152ae42abd2cbcfe8f651cba4a2822ddd07a15d0fe036f72da';

    private int $esperas = 0;

    private function cliente(TransporteFalso $t): Cliente
    {
        $this->esperas = 0;
        return new Cliente([
            'apiKey' => 'k',
            'baseUrl' => 'https://ejemplo.test/api/v1/issuer',
            'ruc' => '20123456789',
            'transporte' => $t,
            'dormir' => function (float $s): void {
                $this->esperas++;
            },
        ]);
    }

    // ---------- Webhooks ----------

    public function testVectorComunDeFirma(): void
    {
        $this->assertSame(self::VECTOR_FIRMA, hash_hmac('sha256', self::VECTOR_CUERPO, self::VECTOR_SECRETO));
        $this->assertTrue(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, self::VECTOR_FIRMA, self::VECTOR_SECRETO));
    }

    public function testFirmaEnMayusculasYConEspaciosSeAcepta(): void
    {
        $this->assertTrue(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, '  ' . strtoupper(self::VECTOR_FIRMA) . "\n", self::VECTOR_SECRETO));
    }

    public function testCuerpoAlteradoNoVerifica(): void
    {
        $this->assertFalse(Cliente::verificarFirmaWebhook('{"evento":"comprobante.rechazado"}', self::VECTOR_FIRMA, self::VECTOR_SECRETO));
        // Re-serializar cambia los bytes (espacios) y rompe la firma: hay que usar el cuerpo crudo.
        $this->assertFalse(Cliente::verificarFirmaWebhook('{"evento": "comprobante.aceptado"}', self::VECTOR_FIRMA, self::VECTOR_SECRETO));
    }

    public function testSecretoEquivocadoNoVerifica(): void
    {
        $this->assertFalse(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, self::VECTOR_FIRMA, 'whsec_otro'));
    }

    public function testFirmaOSecretoVacioDevuelveFalse(): void
    {
        $this->assertFalse(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, '', self::VECTOR_SECRETO));
        $this->assertFalse(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, '   ', self::VECTOR_SECRETO));
        $this->assertFalse(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, self::VECTOR_FIRMA, ''));
    }

    public function testFirmaMalFormadaDevuelveFalseSinExcepcion(): void
    {
        foreach ([
            'sha256=' . self::VECTOR_FIRMA,          // con prefijo
            substr(self::VECTOR_FIRMA, 0, 63),        // corta
            self::VECTOR_FIRMA . '0',                 // larga
            str_repeat('z', 64),                      // no hex
            base64_encode(hex2bin(self::VECTOR_FIRMA)), // otra codificación
            self::VECTOR_FIRMA . "\n0",              // salto de línea en medio
        ] as $firma) {
            $this->assertFalse(Cliente::verificarFirmaWebhook(self::VECTOR_CUERPO, $firma, self::VECTOR_SECRETO), "Aceptó: {$firma}");
        }
    }

    // ---------- codigo / reintentable / campos ----------

    public function testErrorNuevoTraeCodigoReintentableYCampos(): void
    {
        $cuerpo = [
            'limite' => ['limite no debe ser mayor a 100.'],
            'success' => false,
            'message' => 'Datos inválidos: limite no debe ser mayor a 100.',
            'campos' => ['limite' => ['limite no debe ser mayor a 100.']],
            'codigo' => 'DATOS_INVALIDOS',
            'reintentable' => false,
        ];
        $t = new TransporteFalso([TransporteFalso::json(422, $cuerpo)]);
        try {
            $this->cliente($t)->comprobantes->listar(['limite' => 500]);
            $this->fail('Debió lanzar');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(Codigos::DATOS_INVALIDOS, $e->codigo);
            $this->assertFalse($e->reintentable);
            $this->assertSame(['limite' => ['limite no debe ser mayor a 100.']], $e->campos);
            $this->assertSame($e->campos, $e->errores);
            $this->assertSame('Datos inválidos: limite no debe ser mayor a 100.', $e->mensaje);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testErrorDeServidorViejoSinCodigo(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(401, ['success' => false, 'message' => 'API key inválida o cuenta desactivada.'])]);
        try {
            $this->cliente($t)->ping();
            $this->fail('Debió lanzar');
        } catch (ErrorDeAutenticacion $e) {
            $this->assertNull($e->codigo);
            $this->assertFalse($e->reintentable);
            $this->assertSame([], $e->campos);
        }
    }

    public function testConstruccionDirectaDesdeElCuerpo(): void
    {
        $e = new FactuSmartError('x', 500, ['success' => false, 'message' => 'x', 'codigo' => 'ERROR_INTERNO', 'reintentable' => true]);
        $this->assertSame('ERROR_INTERNO', $e->codigo);
        $this->assertTrue($e->reintentable);

        $e = new FactuSmartError('x', 500, '<html>error</html>');
        $this->assertNull($e->codigo);
        $this->assertFalse($e->reintentable);

        // Tipos inesperados no rompen: reintentable "true" (string) no cuenta.
        $e = new FactuSmartError('x', 500, ['codigo' => 123, 'reintentable' => 'true', 'campos' => 'nada']);
        $this->assertNull($e->codigo);
        $this->assertFalse($e->reintentable);
        $this->assertSame([], $e->campos);

        $e = new FactuSmartError('Sin conexión');
        $this->assertNull($e->codigo);
    }

    public function testReintentableTrueSeReintentaEnOperacionesSeguras(): void
    {
        $caido = TransporteFalso::json(500, ['success' => false, 'message' => 'Error interno', 'codigo' => 'ERROR_INTERNO', 'reintentable' => true]);
        $sunat = TransporteFalso::json(422, ['success' => false, 'message' => 'SUNAT no responde', 'codigo' => 'SUNAT_NO_DISPONIBLE', 'reintentable' => true]);
        $ok = TransporteFalso::json(200, ['success' => true, 'external_id' => 'abc', 'aceptado_por_sunat' => true]);

        $t = new TransporteFalso([$caido, $ok]);
        $this->cliente($t)->comprobantes->consultar('abc');
        $this->assertCount(2, $t->peticiones);

        $t = new TransporteFalso([$caido, $sunat, $ok]);
        $this->cliente($t)->comprobantes->emitir(['codigo_tipo_documento' => '03'], ['idempotencyKey' => 'venta-1']);
        $this->assertCount(3, $t->peticiones);
        $this->assertSame(['venta-1', 'venta-1', 'venta-1'], array_map(fn ($p) => $p['cabeceras']['Idempotency-Key'], $t->peticiones));

        $t = new TransporteFalso([$caido, $ok]);
        $this->cliente($t)->rucs->crear(['ruc' => '20609999991']);
        $this->assertCount(2, $t->peticiones);
    }

    public function testUn500SinReintentableNoSeReintenta(): void
    {
        foreach ([
            ['success' => false, 'message' => 'Error interno'],
            ['success' => false, 'message' => 'Error', 'codigo' => 'ERROR_EMISION', 'reintentable' => false],
        ] as $cuerpo) {
            $t = new TransporteFalso([TransporteFalso::json(500, $cuerpo)]);
            try {
                $this->cliente($t)->comprobantes->consultar('abc');
                $this->fail('Debió lanzar');
            } catch (FactuSmartError $e) {
                $this->assertSame(500, $e->status);
            }
            $this->assertCount(1, $t->peticiones);
        }
    }

    public function testSeAgotanLosReintentosConReintentableTrue(): void
    {
        $t = new TransporteFalso(array_fill(0, 4, TransporteFalso::json(500, ['success' => false, 'message' => 'x', 'codigo' => 'ERROR_INTERNO', 'reintentable' => true])));
        try {
            $this->cliente($t)->ping();
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertSame(Codigos::ERROR_INTERNO, $e->codigo);
            $this->assertTrue($e->reintentable);
        }
        $this->assertCount(4, $t->peticiones, '1 intento + 3 reintentos');
        $this->assertSame(3, $this->esperas);
    }

    public function testOperacionesProhibidasNoSeReintentanAunqueDiganReintentable(): void
    {
        $operaciones = [
            'anulaciones.crear' => fn (Cliente $c) => $c->anulaciones->crear(['documentos' => []]),
            'guias.emitir' => fn (Cliente $c) => $c->guias->emitir(['codigo_tipo_documento' => '09']),
            'comprobantes.reenviar' => fn (Cliente $c) => $c->comprobantes->reenviar('abc'),
            'comprobantes.consultarSunat' => fn (Cliente $c) => $c->comprobantes->consultarSunat('abc'),
            'rucs.activar' => fn (Cliente $c) => $c->rucs->activar('20123456789', false),
        ];
        foreach ($operaciones as $nombre => $op) {
            foreach (['ERROR_INTERNO' => 500, 'SUNAT_NO_DISPONIBLE' => 503] as $codigo => $status) {
                $t = new TransporteFalso([
                    TransporteFalso::json($status, ['success' => false, 'message' => 'x', 'codigo' => $codigo, 'reintentable' => true]),
                    TransporteFalso::json(200, ['success' => true]),
                ]);
                try {
                    $op($this->cliente($t));
                    $this->fail("{$nombre} no debió reintentarse");
                } catch (FactuSmartError $e) {
                    $this->assertSame($codigo, $e->codigo);
                    $this->assertTrue($e->reintentable);
                }
                $this->assertCount(1, $t->peticiones, "{$nombre} se reintentó con {$codigo}");
            }
        }
    }

    public function testCodigoLimitePeticionesEsLimiteDePeticiones(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(429, ['success' => false, 'message' => 'Demasiadas peticiones.', 'codigo' => 'LIMITE_PETICIONES', 'reintentable' => true], ['retry-after' => '2'])]);
        try {
            $this->cliente($t)->anulaciones->crear(['documentos' => []]);
            $this->fail('Debió lanzar');
        } catch (LimiteDePeticiones $e) {
            $this->assertSame(Codigos::LIMITE_PETICIONES, $e->codigo);
            $this->assertSame(2, $e->segundosDeEspera);
        }
    }

    public function testDescargaInexistenteCon404JsonEsNoEncontradoConCodigo(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(404, ['success' => false, 'message' => 'Comprobante no encontrado.', 'codigo' => 'NO_ENCONTRADO', 'reintentable' => false])]);
        try {
            $this->cliente($t)->comprobantes->descargar('00000000-0000-4000-8000-000000000000', 'pdf');
            $this->fail('Debió lanzar');
        } catch (NoEncontrado $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('Comprobante no encontrado.', $e->mensaje);
            $this->assertSame(Codigos::NO_ENCONTRADO, $e->codigo);
            $this->assertFalse($e->reintentable);
            $this->assertIsArray($e->respuesta);
        }
        $this->assertSame('*/*', $t->peticiones[0]['cabeceras']['Accept']);
    }

    public function testUserAgentLlevaLaVersion(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, ['success' => true])]);
        $this->cliente($t)->cuenta->saldo();
        $this->assertSame('0.2.0', Cliente::VERSION);
        $this->assertStringStartsWith('factusmart-php/0.2.0 ', $t->peticiones[0]['cabeceras']['User-Agent']);
    }
}
