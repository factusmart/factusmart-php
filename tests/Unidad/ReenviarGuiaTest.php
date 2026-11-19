<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Codigos;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\NoEncontrado;
use PHPUnit\Framework\TestCase;

/** Sin red: reenvío de guías a SUNAT (POST /guias/{id}/reenviar) y código NOTA_EXCEDE_COMPROBANTE (0.8.0). */
final class ReenviarGuiaTest extends TestCase
{
    private const BASE = 'https://ejemplo.test/api/v1/issuer';

    private function cliente(TransporteFalso $t): Cliente
    {
        return new Cliente([
            'apiKey' => 'k',
            'baseUrl' => self::BASE . '/',
            'ruc' => '20100070970',
            'transporte' => $t,
            'dormir' => static function (float $s): void {
            },
        ]);
    }

    public function testReenviarHacePostConRucEnElCuerpoYDevuelveLaRespuesta(): void
    {
        $ok = [
            'success' => true,
            'serie_numero' => 'T001-15',
            'external_id' => 'g-1',
            'enviado' => true,
            'estado' => '03',
            'mensaje' => 'Guía enviada a SUNAT.',
            'links' => ['xml' => 'https://x/guias/g-1/xml', 'pdf' => 'https://x/guias/g-1/pdf', 'cdr' => null],
            'envio' => ['ticket' => 'abc-123'],
            'creditos_consumidos' => 1,
            'creditos_restantes' => 99,
        ];
        $t = new TransporteFalso([TransporteFalso::json(200, $ok), TransporteFalso::json(200, $ok)]);
        $c = $this->cliente($t);

        $r = $c->guias->reenviar('g-1');
        $this->assertTrue($r['enviado']);
        $this->assertSame('T001-15', $r['serie_numero']);
        $this->assertSame($ok['links'], $r['links']);
        $this->assertSame('POST', $t->peticiones[0]['metodo']);
        $this->assertSame(self::BASE . '/guias/g-1/reenviar', $t->peticiones[0]['url']);
        $this->assertSame(['ruc' => '20100070970'], json_decode((string) $t->peticiones[0]['cuerpo'], true));

        $c->guias->reenviar('a/b', ['ruc' => '20111111111']);
        $this->assertSame(self::BASE . '/guias/a%2Fb/reenviar', $t->peticiones[1]['url']);
        $this->assertSame(['ruc' => '20111111111'], json_decode((string) $t->peticiones[1]['cuerpo'], true));
    }

    public function testEnvioFallidoLanzaErrorDeValidacionSinReintentar(): void
    {
        $error = ['success' => false, 'message' => 'SUNAT no respondió al reenvío.', 'codigo' => 'REENVIO_FALLIDO', 'reintentable' => true];
        $t = new TransporteFalso([TransporteFalso::json(422, $error)]);
        try {
            $this->cliente($t)->guias->reenviar('g-1');
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(Codigos::REENVIO_FALLIDO, $e->codigo);
            $this->assertSame($error['message'], $e->mensaje);
            $this->assertTrue($e->reintentable);
        }
        $this->assertCount(1, $t->peticiones, 'Nunca se reintenta solo');
    }

    public function testGuiaQueNoEstaEn01TraeSuEstado(): void
    {
        $error = ['success' => false, 'message' => 'La guía no está en estado Registrado (01).', 'codigo' => 'REENVIO_FALLIDO', 'estado' => '05'];
        $t = new TransporteFalso([TransporteFalso::json(422, $error)]);
        try {
            $this->cliente($t)->guias->reenviar('g-1');
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(Codigos::REENVIO_FALLIDO, $e->codigo);
            $this->assertIsArray($e->respuesta);
            $this->assertSame('05', $e->respuesta['estado']);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testGuiaInexistenteLanzaNoEncontrado(): void
    {
        $error = ['success' => false, 'message' => 'Guía no encontrada.', 'codigo' => 'NO_ENCONTRADO', 'reintentable' => false];
        $t = new TransporteFalso([TransporteFalso::json(404, $error)]);
        try {
            $this->cliente($t)->guias->reenviar('no-existe');
            $this->fail('Debió lanzar NoEncontrado');
        } catch (NoEncontrado $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame(Codigos::NO_ENCONTRADO, $e->codigo);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testOperacionMapeada(): void
    {
        $this->assertSame(['guias', 'reenviar'], Cliente::OPERACIONES['resendDispatchGuide']);
    }

    public function testNotaExcedeComprobante(): void
    {
        $this->assertSame('NOTA_EXCEDE_COMPROBANTE', Codigos::NOTA_EXCEDE_COMPROBANTE);
        $error = [
            'success' => false,
            'message' => 'La nota de crédito excede el comprobante afectado: quedan S/ 20.00 disponibles.',
            'codigo' => 'NOTA_EXCEDE_COMPROBANTE',
            'reintentable' => false,
        ];
        $t = new TransporteFalso([TransporteFalso::json(422, $error)]);
        try {
            $this->cliente($t)->comprobantes->emitir([
                'codigo_tipo_documento' => '07',
                'datos_del_cliente_o_receptor' => ['codigo_tipo_documento_identidad' => '6', 'numero_documento' => '20100070970'],
                'items' => [['descripcion' => 'X', 'cantidad' => 1]],
                'totales' => ['total_venta' => 100],
            ]);
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(Codigos::NOTA_EXCEDE_COMPROBANTE, $e->codigo);
            $this->assertFalse($e->reintentable);
        }
        $this->assertCount(1, $t->peticiones);
    }
}
