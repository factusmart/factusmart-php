<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Codigos;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Excepciones\LimiteDePeticiones;
use FactuSmart\Excepciones\NoEncontrado;
use PHPUnit\Framework\TestCase;

/** Sin red: consulta de RUC/DNI (GET /consultas/{tipo}/{numero}, operationId lookupDocument). */
final class ConsultasTest extends TestCase
{
    private const BASE = 'https://ejemplo.test/api/v1/issuer';

    /** @var array<int, float> */
    private array $esperas = [];

    private function cliente(TransporteFalso $t): Cliente
    {
        $this->esperas = [];
        return new Cliente([
            'apiKey' => 'k',
            'baseUrl' => self::BASE,
            'ruc' => '20100070970', // aunque haya RUC por defecto, la consulta NO lo manda
            'transporte' => $t,
            'dormir' => function (float $s): void {
                $this->esperas[] = $s;
            },
        ]);
    }

    private static function respuestaRuc(): array
    {
        return [
            'success' => true,
            'tipo' => 'ruc',
            'numero' => '20131312955',
            'nombre' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT',
            'direccion' => 'AV. GARCILASO DE LA VEGA - Nro: 1472  - LIMA',
            'estado' => null,
            'condicion' => null,
            'ubigeo' => '150101',
            'desde_cache' => false,
            'consultas_restantes' => 29999,
            'periodo' => 'mes',
            'se_renueva' => '2026-11-23',
        ];
    }

    public function testConsultarRucEsGetSinRucEmisor(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, self::respuestaRuc())]);
        $r = $this->cliente($t)->consultas->consultarRuc('20131312955');

        $p = $t->peticiones[0];
        $this->assertSame('GET', $p['metodo']);
        $this->assertSame(self::BASE . '/consultas/ruc/20131312955', $p['url']);
        $this->assertStringNotContainsString('ruc=', $p['url']);
        $this->assertNull($p['cuerpo']);
        $this->assertArrayNotHasKey('X-RUC', $p['cabeceras']);
        $this->assertSame('k', $p['cabeceras']['X-API-Key']);

        $this->assertSame('SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA - SUNAT', $r['nombre']);
        $this->assertSame('150101', $r['ubigeo']);
        $this->assertNull($r['estado']);
        $this->assertFalse($r['desde_cache']);
        $this->assertSame(29999, $r['consultas_restantes']);
        $this->assertSame('mes', $r['periodo']);
        $this->assertSame('2026-11-23', $r['se_renueva']);
    }

    public function testConsultarDniYGenerico(): void
    {
        $dni = ['success' => true, 'tipo' => 'dni', 'numero' => '12345678', 'nombre' => 'JUAN CARLOS PEREZ GARCIA',
            'direccion' => null, 'estado' => null, 'condicion' => null, 'ubigeo' => null, 'desde_cache' => true,
            'consultas_restantes' => 49, 'periodo' => 'dia', 'se_renueva' => '2026-11-07'];
        $t = new TransporteFalso([TransporteFalso::json(200, $dni), TransporteFalso::json(200, $dni)]);
        $c = $this->cliente($t);

        $r = $c->consultas->consultarDni('12345678');
        $this->assertSame(self::BASE . '/consultas/dni/12345678', $t->peticiones[0]['url']);
        $this->assertSame('JUAN CARLOS PEREZ GARCIA', $r['nombre']);
        $this->assertNull($r['direccion']);
        $this->assertTrue($r['desde_cache']);
        $this->assertSame('dia', $r['periodo']);

        $c->consultas->consultar('DNI', ' 12345678 ');
        $this->assertSame(self::BASE . '/consultas/dni/12345678', $t->peticiones[1]['url']);
    }

    public function testTipoInvalidoFallaSinPeticion(): void
    {
        $t = new TransporteFalso([]);
        try {
            $this->cliente($t)->consultas->consultar('ce', '001234567');
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(Codigos::DATOS_INVALIDOS, $e->codigo);
            $this->assertArrayHasKey('tipo', $e->campos);
        }
        $this->assertCount(0, $t->peticiones);
    }

    public function testNumeroVacioFallaSinPeticion(): void
    {
        $t = new TransporteFalso([]);
        $this->expectException(FactuSmartError::class);
        $this->cliente($t)->consultas->consultarRuc('  ');
    }

    public function testLimiteConsultasNoSeReintenta(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(429, [
                'success' => false,
                'message' => 'Se acabó tu cupo de consultas del mes. Se renueva el 2026-11-23.',
                'codigo' => 'LIMITE_CONSULTAS',
                'reintentable' => false,
                'consultas_restantes' => 0,
                'limite_consultas' => 30000,
                'periodo' => 'mes',
                'se_renueva' => '2026-11-23',
            ], ['retry-after' => '60']),
            TransporteFalso::json(200, self::respuestaRuc()),
        ]);
        try {
            $this->cliente($t)->consultas->consultarRuc('20131312955');
            $this->fail('Debió lanzar LimiteDePeticiones');
        } catch (LimiteDePeticiones $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(Codigos::LIMITE_CONSULTAS, $e->codigo);
            $this->assertFalse($e->reintentable);
            $this->assertStringStartsWith('Se acabó tu cupo', $e->mensaje);
            $this->assertSame('2026-11-23', $e->respuesta['se_renueva']);
        }
        $this->assertCount(1, $t->peticiones, 'LIMITE_CONSULTAS: exactamente 1 petición');
        $this->assertSame([], $this->esperas, 'LIMITE_CONSULTAS: no debió esperar');
    }

    public function testLimitePeticionesSiSeReintenta(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(429, ['success' => false, 'message' => 'Demasiadas peticiones.', 'codigo' => 'LIMITE_PETICIONES', 'reintentable' => true], ['retry-after' => '2']),
            TransporteFalso::json(200, self::respuestaRuc()),
        ]);
        $r = $this->cliente($t)->consultas->consultarRuc('20131312955');
        $this->assertTrue($r['success']);
        $this->assertCount(2, $t->peticiones);
        $this->assertSame([2.0], $this->esperas);
    }

    public function testConsultaNoDisponibleSeReintenta(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(503, ['success' => false, 'message' => 'SUNAT no responde.', 'codigo' => 'CONSULTA_NO_DISPONIBLE', 'reintentable' => true]),
            TransporteFalso::json(200, self::respuestaRuc()),
        ]);
        $r = $this->cliente($t)->consultas->consultarRuc('20131312955');
        $this->assertSame('ruc', $r['tipo']);
        $this->assertCount(2, $t->peticiones);
    }

    public function testNoEncontradoY422NoSeReintentan(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(404, ['success' => false, 'message' => 'No se encontró el DNI.', 'codigo' => 'NO_ENCONTRADO', 'reintentable' => false]),
        ]);
        try {
            $this->cliente($t)->consultas->consultarDni('12345678');
            $this->fail('Debió lanzar NoEncontrado');
        } catch (NoEncontrado $e) {
            $this->assertSame(Codigos::NO_ENCONTRADO, $e->codigo);
        }
        $this->assertCount(1, $t->peticiones);

        $t = new TransporteFalso([
            TransporteFalso::json(422, ['success' => false, 'message' => 'RUC inválido.', 'codigo' => 'DATOS_INVALIDOS', 'reintentable' => false, 'campos' => ['numero' => ['RUC inválido.']]]),
        ]);
        try {
            $this->cliente($t)->consultas->consultarRuc('20131312950');
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(['numero' => ['RUC inválido.']], $e->campos);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testRaizEsGetSinRuc(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, ['success' => true, 'api' => 'FactuSmart', 'docs' => 'https://x', 'estado' => 'ok'])]);
        $r = $this->cliente($t)->raiz();
        $this->assertSame('ok', $r['estado']);
        $this->assertSame('GET', $t->peticiones[0]['metodo']);
        $this->assertSame(self::BASE . '/', $t->peticiones[0]['url']);
        $this->assertSame(['apiRoot' => [null, 'raiz']], ['apiRoot' => Cliente::OPERACIONES['apiRoot']]);
    }

    public function testContratoMapeaLookupDocument(): void
    {
        $this->assertSame(['consultas', 'consultar'], Cliente::OPERACIONES['lookupDocument']);
    }
}
