<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Excepciones\FactuSmartError;
use PHPUnit\Framework\TestCase;

/** Sin red: baseUrl opcional según el formato de la llave (0.9.0). */
final class UrlBaseTest extends TestCase
{
    private const APP = 'https://app.factusmart.pe/api/v1/issuer';

    private static function llaveNueva(): string
    {
        return 'fs_live_A0123456789B_' . str_repeat('a1B2c3D4', 4);
    }

    /** @return array<int, string> */
    private static function llavesAntiguas(): array
    {
        return [
            'k',
            'fs_test_' . str_repeat('a', 40),                      // fs_test_ viejo, sin la cuenta
            'fs_live_AILOU0000000_' . str_repeat('a', 32),         // I, L, O, U no están en el alfabeto
            'fs_live_A0123456789B_' . str_repeat('a', 31),         // secreto corto
            'fs_live_A0123456789B_' . str_repeat('a', 32) . '-',
        ];
    }

    private static function cliente(array $opciones, TransporteFalso $t): Cliente
    {
        return new Cliente($opciones + ['ruc' => '20100070970', 'transporte' => $t]);
    }

    public function testReconoceLlavesNuevas(): void
    {
        $this->assertTrue(Cliente::esLlaveNueva(self::llaveNueva()));
        $this->assertTrue(Cliente::esLlaveNueva('fs_test_AZYXWVTSRQPN_' . str_repeat('Q', 128)));
        $this->assertFalse(Cliente::esLlaveNueva('fs_test_AZYXWVTSRQPN_' . str_repeat('Q', 129)));
        $this->assertFalse(Cliente::esLlaveNueva(self::llaveNueva() . "\n"));
        foreach (self::llavesAntiguas() as $llave) {
            $this->assertFalse(Cliente::esLlaveNueva($llave), $llave);
        }
    }

    public function testLlaveNuevaSinBaseUrlUsaApp(): void
    {
        $this->assertSame(self::APP, Cliente::URL_BASE_PREDETERMINADA);
        foreach ([[], ['baseUrl' => ''], ['baseUrl' => '   '], ['baseUrl' => null]] as $extra) {
            $t = new TransporteFalso([TransporteFalso::json(200, ['success' => true])]);
            self::cliente(['apiKey' => self::llaveNueva()] + $extra, $t)->ping();
            $this->assertSame(self::APP . '/ping?ruc=20100070970', $t->peticiones[0]['url']);
        }
    }

    public function testLlaveAntiguaSinBaseUrlLanzaError(): void
    {
        foreach (self::llavesAntiguas() as $llave) {
            try {
                new Cliente(['apiKey' => $llave]);
                $this->fail('Debió lanzar con ' . $llave);
            } catch (FactuSmartError $e) {
                $this->assertStringStartsWith('Falta baseUrl: tu llave es del formato anterior;', $e->mensaje);
                $this->assertStringContainsString('fs_live_…', $e->mensaje);
            }
        }
    }

    public function testBaseUrlExplicitaSeRespeta(): void
    {
        foreach ([self::llaveNueva(), 'k'] as $llave) {
            $t = new TransporteFalso([TransporteFalso::json(200, ['success' => true])]);
            self::cliente(['apiKey' => $llave, 'baseUrl' => 'https://s2.factusmart.pe/api/v1/issuer/'], $t)->ping();
            $this->assertSame('https://s2.factusmart.pe/api/v1/issuer/ping?ruc=20100070970', $t->peticiones[0]['url']);
        }
    }
}
