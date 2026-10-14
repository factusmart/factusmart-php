<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Integracion;

use FactuSmart\Cliente;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas 10 y 11 de DISENO_SDK.md contra el API LOCAL (Laragon). NO emiten nada ni crean locales:
 * `establecimientos->crear()` se prueba solo sin red (el plan del RUC demo tiene límite de locales).
 */
final class AnexosYFormatoTest extends TestCase
{
    private static function cliente(): Cliente
    {
        $base = (string) getenv('FACTUSMART_BASE_URL');
        if (!preg_match('#^https?://[^/]*(\.test|localhost|127\.0\.0\.1)[:/]#i', $base)) {
            self::fail("Estas pruebas solo corren contra un API local; FACTUSMART_BASE_URL={$base}");
        }
        return new Cliente([
            'apiKey' => (string) getenv('FACTUSMART_API_KEY'),
            'baseUrl' => $base,
            'ruc' => (string) getenv('FACTUSMART_RUC'),
            'verificarSsl' => getenv('FACTUSMART_VERIFICAR_SSL') !== '0',
        ]);
    }

    public function testP10ListarEstablecimientos(): void
    {
        $r = self::cliente()->establecimientos->listar();
        $this->assertTrue($r['success']);
        $this->assertIsArray($r['establecimientos']);
        $this->assertNotEmpty($r['establecimientos']);
        $this->assertArrayHasKey('codigo_anexo', $r['establecimientos'][0]);
        fwrite(STDERR, "\n  [P10] " . count($r['establecimientos']) . " local(es): "
            . implode(', ', array_map(static fn ($e) => $e['codigo_anexo'] . ' ' . $e['nombre'], $r['establecimientos'])) . "\n");
    }

    public function testBuscarProductoSunatComputador(): void
    {
        $r = self::cliente()->catalogos->buscarProductoSunat('computador', ['limite' => 10]);
        $this->assertTrue($r['success']);
        $this->assertSame('25', $r['catalogo_sunat']);
        $codigos = array_column($r['datos'], 'codigo');
        $this->assertContains('43211503', $codigos);
        fwrite(STDERR, "\n  [Cat.25] q=computador → " . count($codigos) . " resultado(s), total={$r['total']}\n");
    }

    public function testP11PdfTicket58DeUnComprobanteExistente(): void
    {
        $fs = self::cliente();
        $lista = $fs->comprobantes->listar(['limite' => 1]);
        if (($lista['comprobantes'] ?? []) === []) {
            $this->markTestSkipped('El RUC demo no tiene comprobantes; esta prueba no emite.');
        }
        $id = $lista['comprobantes'][0]['external_id'];

        $pdf = $fs->comprobantes->descargar($id, 'pdf', ['formato' => 'ticket58']);
        $this->assertStringStartsWith('%PDF', $pdf);
        fwrite(STDERR, "\n  [P11] PDF ticket58 de {$id}: " . strlen($pdf) . " bytes\n");
    }
}
