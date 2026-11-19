<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Codigos;
use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Excepciones\NoEncontrado;
use FactuSmart\Http\Respuesta;
use PHPUnit\Framework\TestCase;

/** Sin red: descargas de guías (pdf/xml/cdr) y de anulaciones (xml/cdr), y `links` en las respuestas (0.7.0). */
final class DescargasGuiasYAnulacionesTest extends TestCase
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

    private static function archivo(string $tipo, string $cuerpo): Respuesta
    {
        return new Respuesta(200, ['content-type' => $tipo], $cuerpo);
    }

    public function testGuiaDescargarDevuelveLosBytesConRucEnLaQuery(): void
    {
        $t = new TransporteFalso([
            self::archivo('application/pdf', '%PDF-1.4 guia'),
            self::archivo('application/xml', '<DespatchAdvice/>'),
            self::archivo('application/zip', "PK\x03\x04cdr"),
        ]);
        $c = $this->cliente($t);

        $this->assertSame('%PDF-1.4 guia', $c->guias->descargar('g-1', 'pdf'));
        $this->assertSame('GET', $t->peticiones[0]['metodo']);
        $this->assertSame(self::BASE . '/guias/g-1/pdf?ruc=20100070970', $t->peticiones[0]['url']);

        $this->assertSame('<DespatchAdvice/>', $c->guias->descargar('g-1', 'XML', ['ruc' => '20111111111']));
        $this->assertSame(self::BASE . '/guias/g-1/xml?ruc=20111111111', $t->peticiones[1]['url']);

        $this->assertSame("PK\x03\x04cdr", $c->guias->descargar('g-1', 'cdr'));
        $this->assertSame(self::BASE . '/guias/g-1/cdr?ruc=20100070970', $t->peticiones[2]['url']);
    }

    public function testGuiaDescargar404LanzaNoEncontradoSinReintentar(): void
    {
        $error = [
            'success' => false,
            'message' => 'El CDR de esta guía no está disponible todavía. Consulta GET /guias/{external_id}/status y vuelve a intentar.',
            'codigo' => 'NO_ENCONTRADO',
            'reintentable' => false,
        ];
        $t = new TransporteFalso([TransporteFalso::json(404, $error)]);
        try {
            $this->cliente($t)->guias->descargar('g-1', 'cdr');
            $this->fail('Debió lanzar NoEncontrado');
        } catch (NoEncontrado $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame(Codigos::NO_ENCONTRADO, $e->codigo);
            $this->assertSame($error['message'], $e->mensaje);
            $this->assertFalse($e->reintentable);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testGuiaDescargarTipoInvalidoLanzaSinPeticion(): void
    {
        $t = new TransporteFalso([]);
        $this->expectException(FactuSmartError::class);
        try {
            $this->cliente($t)->guias->descargar('g-1', 'zip');
        } finally {
            $this->assertSame([], $t->peticiones);
        }
    }

    public function testAnulacionDescargarXmlYCdrConTicketCodificado(): void
    {
        $t = new TransporteFalso([
            self::archivo('application/xml', '<VoidedDocuments/>'),
            self::archivo('application/zip', "PK\x03\x04cdr"),
        ]);
        $c = $this->cliente($t);

        $this->assertSame('<VoidedDocuments/>', $c->anulaciones->descargar('1731000000123', 'xml'));
        $this->assertSame('GET', $t->peticiones[0]['metodo']);
        $this->assertSame(self::BASE . '/anulaciones/1731000000123/xml?ruc=20100070970', $t->peticiones[0]['url']);

        $this->assertSame("PK\x03\x04cdr", $c->anulaciones->descargar('a/b', 'CDR', ['ruc' => '20111111111']));
        $this->assertSame(self::BASE . '/anulaciones/a%2Fb/cdr?ruc=20111111111', $t->peticiones[1]['url']);
    }

    public function testAnulacionDescargar404LanzaNoEncontrado(): void
    {
        $error = ['success' => false, 'message' => 'Anulación no encontrada.', 'codigo' => 'NO_ENCONTRADO', 'reintentable' => false];
        $t = new TransporteFalso([TransporteFalso::json(404, $error)]);
        try {
            $this->cliente($t)->anulaciones->descargar('999', 'cdr');
            $this->fail('Debió lanzar NoEncontrado');
        } catch (NoEncontrado $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame(Codigos::NO_ENCONTRADO, $e->codigo);
            $this->assertSame('Anulación no encontrada.', $e->mensaje);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testAnulacionNoTienePdfNiAceptaTicketVacio(): void
    {
        foreach ([['999', 'pdf'], ['999', 'zip'], ['  ', 'xml']] as [$ticket, $tipo]) {
            $t = new TransporteFalso([]);
            try {
                $this->cliente($t)->anulaciones->descargar($ticket, $tipo);
                $this->fail("({$ticket}, {$tipo}) debió lanzar");
            } catch (FactuSmartError $e) {
                $this->assertNotInstanceOf(NoEncontrado::class, $e);
                $this->assertSame([], $t->peticiones, 'No debe hacer petición');
            }
        }
    }

    public function testLasRespuestasTraenLinksTalCual(): void
    {
        $links = ['pdf' => 'https://x/documents/abc/pdf', 'pdf_ticket' => 'https://x/documents/abc/pdf?formato=ticket', 'xml' => 'https://x/documents/abc/xml', 'cdr' => 'https://x/documents/abc/cdr'];
        $linksGuia = ['xml' => 'https://x/guias/g/xml', 'pdf' => 'https://x/guias/g/pdf', 'cdr' => 'https://x/guias/g/cdr'];
        $linksAnulacion = ['xml' => 'https://x/anulaciones/t/xml', 'cdr' => 'https://x/anulaciones/t/cdr'];
        $t = new TransporteFalso([
            TransporteFalso::json(200, ['success' => true, 'estado' => '05', 'serie_numero' => 'F001-1', 'external_id' => 'abc', 'links' => $links]),
            TransporteFalso::json(200, ['success' => true, 'external_id' => 'g', 'estado' => '05', 'links' => $linksGuia]),
            TransporteFalso::json(200, ['success' => true, 'tipo' => 'baja', 'ticket' => 't', 'estado' => '05', 'external_id' => 'a', 'documentos' => [], 'links' => $linksAnulacion]),
        ]);
        $c = $this->cliente($t);
        $this->assertSame($links, $c->comprobantes->consultar('abc')['links']);
        $this->assertSame($linksGuia, $c->guias->estado('g')['links']);
        $this->assertSame($linksAnulacion, $c->anulaciones->estado('t')['links']);
    }
}
