<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Codigos;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Excepciones\RucNoAutorizado;
use FactuSmart\Http\ErrorDeRed;
use FactuSmart\Http\Respuesta;
use PHPUnit\Framework\TestCase;

/** Sin red: anexos (establecimientos) y PDF por tamaño (`formato`). Pruebas 10 y 11 de DISENO_SDK.md. */
final class AnexosYFormatoTest extends TestCase
{
    private const BASE = 'https://ejemplo.test/api/v1/issuer';

    private function cliente(TransporteFalso $t, array $extra = []): Cliente
    {
        return new Cliente($extra + [
            'apiKey' => 'k',
            'baseUrl' => self::BASE . '/',
            'ruc' => '20123456789',
            'transporte' => $t,
            'dormir' => static function (float $s): void {
            },
        ]);
    }

    private static function pdf(): Respuesta
    {
        return new Respuesta(200, ['content-type' => 'application/pdf'], '%PDF-1.4 falso');
    }

    public function testListarEstablecimientosEsGetConRucEnLaQuery(): void
    {
        $respuesta = ['success' => true, 'establecimientos' => [['codigo_anexo' => '0000', 'predeterminado' => true]]];
        $t = new TransporteFalso([TransporteFalso::json(200, $respuesta), TransporteFalso::json(200, $respuesta)]);
        $c = $this->cliente($t);

        $this->assertSame($respuesta, $c->establecimientos->listar());
        $this->assertSame('GET', $t->peticiones[0]['metodo']);
        $this->assertSame(self::BASE . '/establecimientos?ruc=20123456789', $t->peticiones[0]['url']);
        $this->assertNull($t->peticiones[0]['cuerpo']);

        $c->establecimientos->listar(['ruc' => '20111111111']);
        $this->assertSame(self::BASE . '/establecimientos?ruc=20111111111', $t->peticiones[1]['url']);
    }

    public function testListarEstablecimientosSeReintentaComoUnGet(): void
    {
        $t = new TransporteFalso([
            new ErrorDeRed('timeout'),
            TransporteFalso::json(503, ['success' => false, 'message' => 'caído']),
            TransporteFalso::json(200, ['success' => true, 'establecimientos' => []]),
        ]);
        $this->cliente($t)->establecimientos->listar();
        $this->assertCount(3, $t->peticiones);
    }

    public function testCrearEstablecimientoEsPostConRucEnElCuerpo(): void
    {
        $datos = [
            'codigo_anexo' => '0001',
            'nombre' => 'Tienda Miraflores',
            'direccion' => 'Av. Larco 345',
            'ubigeo' => '150122',
            'series' => [['codigo_tipo_documento' => '03', 'serie' => 'B002']],
        ];
        $creado = ['success' => true, 'message' => 'Local creado.', 'establecimiento' => ['codigo_anexo' => '0001']];
        $t = new TransporteFalso([TransporteFalso::json(201, $creado), TransporteFalso::json(201, $creado), TransporteFalso::json(201, $creado)]);
        $c = $this->cliente($t);

        $this->assertSame($creado, $c->establecimientos->crear($datos));
        $p = $t->peticiones[0];
        $this->assertSame('POST', $p['metodo']);
        $this->assertSame(self::BASE . '/establecimientos', $p['url'], 'El ruc va en el cuerpo, no en la query');
        $this->assertSame('application/json', $p['cabeceras']['Content-Type']);
        $this->assertArrayNotHasKey('Idempotency-Key', $p['cabeceras']);
        $this->assertSame($datos + ['ruc' => '20123456789'], json_decode((string) $p['cuerpo'], true));

        $c->establecimientos->crear($datos, ['ruc' => '20111111111']);
        $this->assertSame('20111111111', json_decode((string) $t->peticiones[1]['cuerpo'], true)['ruc']);

        $c->establecimientos->crear($datos + ['ruc' => '20999999999'], ['ruc' => '20111111111']);
        $this->assertSame('20999999999', json_decode((string) $t->peticiones[2]['cuerpo'], true)['ruc'], 'El ruc de los datos manda');
    }

    public function testCrearEstablecimientoNuncaSeReintenta(): void
    {
        foreach ([TransporteFalso::json(503, ['success' => false, 'message' => 'caído']), new ErrorDeRed('timeout')] as $falla) {
            $t = new TransporteFalso([$falla, TransporteFalso::json(201, ['success' => true])]);
            try {
                $this->cliente($t)->establecimientos->crear(['codigo_anexo' => '0001', 'nombre' => 'X']);
                $this->fail('Debió lanzar');
            } catch (FactuSmartError) {
                $this->assertCount(1, $t->peticiones, 'crear no debe reintentarse');
            }
        }
    }

    public function testLimiteDeEstablecimientosEsRucNoAutorizadoConCodigo(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(403, [
            'success' => false,
            'message' => 'Ha alcanzado el límite de 1 sucursal(es) permitidas por su plan.',
            'codigo' => 'LIMITE_ESTABLECIMIENTOS',
            'reintentable' => false,
        ])]);
        try {
            $this->cliente($t)->establecimientos->crear(['codigo_anexo' => '0001', 'nombre' => 'X']);
            $this->fail('Debió lanzar');
        } catch (RucNoAutorizado $e) {
            $this->assertSame(Codigos::LIMITE_ESTABLECIMIENTOS, $e->codigo);
            $this->assertFalse($e->reintentable);
            $this->assertCount(1, $t->peticiones);
        }
    }

    public function testBuscarProductoSunatEsGetConQLimiteYRuc(): void
    {
        $respuesta = ['success' => true, 'catalogo' => 'productos-sunat', 'catalogo_sunat' => '25', 'q' => 'computador',
            'total' => 1, 'hay_mas' => false, 'datos' => [['codigo' => '43211503', 'descripcion' => 'Computadores portátiles']]];
        $t = new TransporteFalso([
            new ErrorDeRed('timeout'),
            TransporteFalso::json(200, $respuesta),
            TransporteFalso::json(200, $respuesta),
        ]);
        $c = $this->cliente($t);

        $this->assertSame($respuesta, $c->catalogos->buscarProductoSunat('computador'));
        $this->assertCount(2, $t->peticiones, 'Se reintenta como un GET');
        $this->assertSame('GET', $t->peticiones[1]['metodo']);
        $this->assertSame(self::BASE . '/catalogos/productos-sunat?q=computador&ruc=20123456789', $t->peticiones[1]['url']);

        $c->catalogos->buscarProductoSunat('silla oficina', ['limite' => 5, 'nivel' => 'producto', 'ruc' => '20111111111']);
        $this->assertSame(self::BASE . '/catalogos/productos-sunat?q=silla%20oficina&limite=5&nivel=producto&ruc=20111111111', $t->peticiones[2]['url']);

        $this->expectException(FactuSmartError::class);
        $c->catalogos->buscarProductoSunat('  ');
    }

    public function testDescargarSinFormatoNoMandaElParametro(): void
    {
        $t = new TransporteFalso([self::pdf(), self::pdf()]);
        $c = $this->cliente($t);
        $this->assertSame('%PDF-1.4 falso', $c->comprobantes->descargar('abc', 'pdf'));
        $this->assertSame(self::BASE . '/documents/abc/pdf?ruc=20123456789', $t->peticiones[0]['url']);

        $c->comprobantes->descargar('abc', 'pdf', ['formato' => '']);
        $this->assertStringNotContainsString('formato', $t->peticiones[1]['url']);
    }

    public function testDescargarConFormatoMandaLaQuery(): void
    {
        $t = new TransporteFalso([self::pdf(), self::pdf(), self::pdf()]);
        $c = $this->cliente($t);

        $c->comprobantes->descargar('abc', 'pdf', ['formato' => 'ticket']);
        $this->assertSame('GET', $t->peticiones[0]['metodo']);
        $this->assertSame(self::BASE . '/documents/abc/pdf?ruc=20123456789&formato=ticket', $t->peticiones[0]['url']);

        $c->comprobantes->descargar('abc', 'PDF', ['formato' => 'Ticket58', 'ruc' => '20111111111']);
        $this->assertSame(self::BASE . '/documents/abc/pdf?ruc=20111111111&formato=ticket58', $t->peticiones[1]['url']);

        $c->comprobantes->descargar('abc', 'pdf', ['formato' => 'a5']);
        $this->assertStringEndsWith('&formato=a5', $t->peticiones[2]['url']);
    }

    public function testFormatoConXmlOCdrLanzaSinPeticion(): void
    {
        foreach (['xml', 'cdr'] as $tipo) {
            $t = new TransporteFalso([]);
            try {
                $this->cliente($t)->comprobantes->descargar('abc', $tipo, ['formato' => 'ticket']);
                $this->fail("formato con {$tipo} debió lanzar");
            } catch (ErrorDeValidacion $e) {
                $this->assertSame(422, $e->status);
                $this->assertSame(Codigos::DATOS_INVALIDOS, $e->codigo);
                $this->assertArrayHasKey('formato', $e->campos);
                $this->assertArrayHasKey('formato', $e->errores);
                $this->assertFalse($e->reintentable);
                $this->assertSame([], $t->peticiones, 'No debe hacer petición');
            }
        }
    }

    public function testFormatoInvalidoLanzaSinPeticion(): void
    {
        foreach (['ticket_58', 'a3', 'carta'] as $formato) {
            $t = new TransporteFalso([]);
            try {
                $this->cliente($t)->comprobantes->descargar('abc', 'pdf', ['formato' => $formato]);
                $this->fail("formato {$formato} debió lanzar");
            } catch (ErrorDeValidacion $e) {
                $this->assertSame(422, $e->status);
                $this->assertSame(Codigos::DATOS_INVALIDOS, $e->codigo);
                $this->assertArrayHasKey('formato', $e->campos);
                $this->assertStringContainsString('ticket58', $e->mensaje, 'El mensaje lista los formatos válidos');
                $this->assertSame([], $t->peticiones);
            }
        }
    }

    public function testPdfNoGeneradoSeReintentaPorqueEsGetYDiceReintentable(): void
    {
        // Regla 0.2.0: un GET se repite si el cuerpo dice `reintentable: true`, aunque sea 500.
        $error = ['success' => false, 'message' => 'No se pudo generar el PDF.', 'codigo' => 'PDF_NO_GENERADO', 'reintentable' => true];
        $t = new TransporteFalso([TransporteFalso::json(500, $error), self::pdf()]);
        $this->assertSame('%PDF-1.4 falso', $this->cliente($t)->comprobantes->descargar('abc', 'pdf', ['formato' => 'ticket58']));
        $this->assertCount(2, $t->peticiones);

        $t = new TransporteFalso(array_fill(0, 4, TransporteFalso::json(500, $error)));
        try {
            $this->cliente($t)->comprobantes->descargar('abc', 'pdf', ['formato' => 'ticket58']);
            $this->fail('Debió lanzar al agotar los reintentos');
        } catch (FactuSmartError $e) {
            $this->assertSame(Codigos::PDF_NO_GENERADO, $e->codigo);
            $this->assertTrue($e->reintentable);
            $this->assertCount(4, $t->peticiones);
        }
    }
}
