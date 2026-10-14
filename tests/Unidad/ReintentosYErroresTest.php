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
use FactuSmart\Excepciones\RucNoAutorizado;
use FactuSmart\Excepciones\SinCreditos;
use FactuSmart\Http\ErrorDeRed;
use FactuSmart\Http\Respuesta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pruebas sin red: reintentos, idempotencia, mapeo HTTP → excepción y defaults de emitir. */
final class ReintentosYErroresTest extends TestCase
{
    /** @var array<int, float> */
    private array $esperas = [];

    private function cliente(TransporteFalso $t, array $extra = []): Cliente
    {
        $this->esperas = [];
        return new Cliente($extra + [
            'apiKey' => 'k',
            'baseUrl' => 'https://ejemplo.test/api/v1/issuer/',
            'ruc' => '20100070970',
            'transporte' => $t,
            'dormir' => function (float $s): void {
                $this->esperas[] = $s;
            },
        ]);
    }

    private static function emisionOk(array $extra = []): Respuesta
    {
        return TransporteFalso::json(200, $extra + [
            'success' => true, 'estado' => '05', 'serie_numero' => 'BA01-1',
            'external_id' => 'abc', 'creditos_restantes' => 10,
            'aceptado_por_sunat' => true, 'entorno' => 'prueba', 'tiene_validez_fiscal' => false,
        ]);
    }

    private static function cabeceraIdem(array $peticion): string
    {
        return $peticion['cabeceras']['Idempotency-Key'] ?? '';
    }

    public function testElReintentoDeEmitirReusaLaMismaIdempotencyKey(): void
    {
        $t = new TransporteFalso([
            new ErrorDeRed('timeout'),
            TransporteFalso::json(503, ['success' => false, 'message' => 'Servicio no disponible']),
            TransporteFalso::json(409, ['success' => false, 'message' => 'Ya hay una emisión en curso con esa Idempotency-Key.']),
            self::emisionOk(),
        ]);
        $r = $this->cliente($t)->comprobantes->emitir(['codigo_tipo_documento' => '03']);

        $this->assertSame('abc', $r['external_id']);
        $this->assertCount(4, $t->peticiones);
        $clave = self::cabeceraIdem($t->peticiones[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $clave);
        foreach ($t->peticiones as $p) {
            $this->assertSame($clave, self::cabeceraIdem($p), 'Todos los reintentos deben llevar la misma Idempotency-Key');
        }
        $this->assertSame([1.0, 2.0, 4.0], $this->esperas, 'Espera creciente 1, 2, 4 s');
    }

    public function testEmitirUsaLaIdempotencyKeyDelUsuario(): void
    {
        $t = new TransporteFalso([self::emisionOk()]);
        $this->cliente($t)->comprobantes->emitir(['codigo_tipo_documento' => '03'], ['idempotencyKey' => 'venta-123']);
        $this->assertSame('venta-123', self::cabeceraIdem($t->peticiones[0]));
    }

    public function testDosLlamadasDistintasGeneranClavesDistintas(): void
    {
        $t = new TransporteFalso([self::emisionOk(), self::emisionOk()]);
        $c = $this->cliente($t);
        $c->comprobantes->emitir(['codigo_tipo_documento' => '03']);
        $c->comprobantes->emitir(['codigo_tipo_documento' => '03']);
        $this->assertNotSame(self::cabeceraIdem($t->peticiones[0]), self::cabeceraIdem($t->peticiones[1]));
    }

    public function testCon200YAceptadoPorSunatFalseNoLanzaExcepcion(): void
    {
        $t = new TransporteFalso([self::emisionOk([
            'estado' => '01', 'aceptado_por_sunat' => false,
            'sunat' => ['codigo' => null, 'descripcion' => 'SUNAT no respondió'],
            'accion_requerida' => 'Reintenta con /reenviar',
        ])]);
        $r = $this->cliente($t)->comprobantes->emitir(['codigo_tipo_documento' => '01']);
        $this->assertFalse($r['aceptado_por_sunat']);
        $this->assertSame('01', $r['estado']);
        $this->assertCount(1, $t->peticiones, 'Un 200 no se reintenta aunque SUNAT no haya aceptado');
    }

    public static function casosDeError(): array
    {
        return [
            'HTTP 401' => [401, ['success' => false, 'message' => 'API key inválida o cuenta desactivada.'], ErrorDeAutenticacion::class],
            'HTTP 402' => [402, ['success' => false, 'message' => 'Sin créditos', 'creditos_restantes' => 0], SinCreditos::class],
            'HTTP 403' => [403, ['success' => false, 'message' => 'El RUC indicado no pertenece a esta cuenta o no existe.'], RucNoAutorizado::class],
            'HTTP 404' => [404, ['success' => false, 'message' => 'Comprobante no encontrado.'], NoEncontrado::class],
            'HTTP 422' => [422, ['success' => false, 'message' => 'Datos del comprobante inválidos o incompletos.'], ErrorDeValidacion::class],
            'HTTP 429' => [429, ['success' => false, 'message' => 'Espera 7 segundos'], LimiteDePeticiones::class],
            'HTTP 500 Too Many Attempts' => [500, ['success' => false, 'message' => 'Too Many Attempts.', 'file' => 'x', 'line' => 1], LimiteDePeticiones::class],
            'HTTP 500' => [500, ['success' => false, 'message' => 'Error interno'], FactuSmartError::class],
            'HTTP 409' => [409, ['success' => false, 'message' => 'Conflicto'], FactuSmartError::class],
        ];
    }

    #[DataProvider('casosDeError')]
    public function testMapeoHttpAExcepcion(int $status, array $cuerpo, string $clase): void
    {
        // anulaciones->crear nunca reintenta: una sola respuesta basta para todos los casos.
        $t = new TransporteFalso([TransporteFalso::json($status, $cuerpo)]);
        try {
            $this->cliente($t)->anulaciones->crear(['documentos' => []]);
            $this->fail('Debió lanzar ' . $clase);
        } catch (FactuSmartError $e) {
            $this->assertSame($clase, $e::class);
            $this->assertSame($status, $e->status);
            $this->assertSame($cuerpo, $e->respuesta);
            $this->assertStringContainsString((string) $cuerpo['message'], $e->mensaje);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testLimiteDePeticionesLeeRetryAfterYElMensaje(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(429, ['success' => false, 'message' => 'Límite'], ['retry-after' => '9']),
            TransporteFalso::json(429, ['success' => false, 'message' => 'Espera 5 segundos e intenta otra vez.']),
            TransporteFalso::json(200, ['success' => true, 'rucs' => []]),
        ]);
        $this->cliente($t)->rucs->listar();
        $this->assertSame([9.0, 5.0], $this->esperas);

        $t = new TransporteFalso([TransporteFalso::json(429, ['success' => false, 'message' => 'Espera 3 segundos'])]);
        try {
            $this->cliente($t, ['maxReintentos' => 0])->cuenta->saldo();
            $this->fail('Debió lanzar LimiteDePeticiones');
        } catch (LimiteDePeticiones $e) {
            $this->assertSame(3, $e->segundosDeEspera);
        }
    }

    public function testLimitePruebasDiarioNoSeReintentaUnaSolaPeticion(): void
    {
        $cuerpo = [
            'success' => false,
            'message' => 'Llegaste al máximo de 200 pruebas por día en modo demo. Mañana se reinicia (medianoche, hora de Perú).',
            'codigo' => 'LIMITE_PRUEBAS_DIARIO',
            'reintentable' => false,
        ];
        // Aunque sea 429 y una operación reintentable (GET y emitir), reintentable:false manda.
        $operaciones = [
            'GET' => fn (Cliente $c) => $c->cuenta->saldo(),
            'emitir' => fn (Cliente $c) => $c->comprobantes->emitir(['codigo_tipo_documento' => '03']),
            'calcular' => fn (Cliente $c) => $c->comprobantes->calcular([['descripcion' => 'A', 'cantidad' => 1, 'valor_unitario' => 1, 'afectacion' => '10']]),
        ];
        foreach ($operaciones as $nombre => $op) {
            $t = new TransporteFalso([
                TransporteFalso::json(429, $cuerpo, ['retry-after' => '60']),
                TransporteFalso::json(200, ['success' => true]),
            ]);
            try {
                $op($this->cliente($t));
                $this->fail("{$nombre}: debió lanzar LimiteDePeticiones");
            } catch (LimiteDePeticiones $e) {
                $this->assertSame(LimiteDePeticiones::class, $e::class, 'No hay subclase nueva');
                $this->assertSame(429, $e->status);
                $this->assertSame(Codigos::LIMITE_PRUEBAS_DIARIO, $e->codigo);
                $this->assertFalse($e->reintentable);
                $this->assertStringStartsWith('Llegaste al máximo', $e->mensaje);
            }
            $this->assertCount(1, $t->peticiones, "{$nombre}: exactamente 1 petición");
            $this->assertSame([], $this->esperas, "{$nombre}: no debió esperar");
        }
    }

    public function testReintentableFalseMandaTambienEn503Y409(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(503, ['success' => false, 'message' => 'No', 'codigo' => 'X', 'reintentable' => false]),
            TransporteFalso::json(200, ['success' => true]),
        ]);
        try {
            $this->cliente($t)->ping();
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertCount(1, $t->peticiones);

        $t = new TransporteFalso([
            TransporteFalso::json(409, ['success' => false, 'message' => 'Conflicto', 'codigo' => 'CONFLICTO', 'reintentable' => false]),
            self::emisionOk(),
        ]);
        try {
            $this->cliente($t)->comprobantes->emitir(['codigo_tipo_documento' => '03']);
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertSame('CONFLICTO', $e->codigo);
        }
        $this->assertCount(1, $t->peticiones);
    }

    public function testUn429ConReintentableTrueSiSeReintenta(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(429, ['success' => false, 'message' => 'Espera 2 segundos', 'codigo' => 'LIMITE_PETICIONES', 'reintentable' => true]),
            TransporteFalso::json(200, ['success' => true, 'creditos_disponibles' => 5]),
        ]);
        $r = $this->cliente($t)->cuenta->saldo();
        $this->assertSame(5, $r['creditos_disponibles']);
        $this->assertCount(2, $t->peticiones);
        $this->assertSame([2.0], $this->esperas);
    }

    public function testCalcularMandaItemsYPorcentajeSinRucYSeReintentaComoUnGet(): void
    {
        $items = [['descripcion' => 'Menu', 'cantidad' => 3, 'precio_unitario' => 10, 'afectacion' => '10']];
        $respuesta = ['success' => true, 'items' => [['total_item' => 30]], 'totales' => ['total_venta' => 30]];
        $t = new TransporteFalso([
            new ErrorDeRed('timeout'),
            TransporteFalso::json(503, ['success' => false, 'message' => 'caído']),
            TransporteFalso::json(200, $respuesta),
            TransporteFalso::json(200, $respuesta),
        ]);
        $c = $this->cliente($t);

        $r = $c->comprobantes->calcular($items, ['porcentaje_igv' => 10.5, 'ruc' => '20111111111']);
        $this->assertSame($respuesta, $r, 'Devuelve la respuesta tal cual');
        $this->assertCount(3, $t->peticiones);
        $p = $t->peticiones[2];
        $this->assertSame('POST', $p['metodo']);
        $this->assertSame('https://ejemplo.test/api/v1/issuer/calcular', $p['url']);
        $this->assertArrayNotHasKey('Idempotency-Key', $p['cabeceras']);
        $this->assertSame(['items' => $items, 'porcentaje_igv' => 10.5], json_decode((string) $p['cuerpo'], true));

        $c->comprobantes->calcular($items);
        $cuerpo = json_decode((string) $t->peticiones[3]['cuerpo'], true);
        $this->assertSame(['items' => $items], $cuerpo, 'Sin porcentaje_igv ni ruc en el cuerpo');
    }

    public function testCatalogosListarYConsultar(): void
    {
        $t = new TransporteFalso([
            TransporteFalso::json(200, ['success' => true, 'catalogos' => [], 'nota' => '']),
            TransporteFalso::json(200, ['success' => true, 'catalogo' => 'unidades', 'total' => 0, 'datos' => []]),
            TransporteFalso::json(200, ['success' => true]),
            TransporteFalso::json(200, ['success' => true]),
        ]);
        $c = $this->cliente($t);
        $c->catalogos->listar();
        $this->assertSame('GET', $t->peticiones[0]['metodo']);
        $this->assertSame('https://ejemplo.test/api/v1/issuer/catalogos?ruc=20100070970', $t->peticiones[0]['url']);

        $r = $c->catalogos->consultar('unidades', ['q' => 'kilo gr']);
        $this->assertSame('unidades', $r['catalogo']);
        $this->assertSame('https://ejemplo.test/api/v1/issuer/catalogos/unidades?q=kilo%20gr&ruc=20100070970', $t->peticiones[1]['url']);

        $c->catalogos->consultar('03', ['ruc' => '20111111111']);
        $this->assertSame('https://ejemplo.test/api/v1/issuer/catalogos/03?ruc=20111111111', $t->peticiones[2]['url']);

        $c->catalogos->consultar('a/b');
        $this->assertSame('https://ejemplo.test/api/v1/issuer/catalogos/a%2Fb?ruc=20100070970', $t->peticiones[3]['url']);
    }

    public function testValidacionLaravelSeVuelveMensajeLegible(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(422, ['limite' => ['El campo limite no debe ser mayor que 100.']])]);
        try {
            $this->cliente($t)->comprobantes->listar(['limite' => 500]);
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(['limite' => ['El campo limite no debe ser mayor que 100.']], $e->errores);
            $this->assertStringContainsString('limite: El campo limite no debe ser mayor que 100.', $e->mensaje);
        }
    }

    public function testNoSeReintentanOperacionesPeligrosas(): void
    {
        $operaciones = [
            fn (Cliente $c) => $c->anulaciones->crear(['documentos' => []]),
            fn (Cliente $c) => $c->guias->emitir(['codigo_tipo_documento' => '09']),
            fn (Cliente $c) => $c->comprobantes->reenviar('abc'),
            fn (Cliente $c) => $c->comprobantes->consultarSunat('abc'),
            fn (Cliente $c) => $c->rucs->activar('20100070970', false),
        ];
        foreach ($operaciones as $i => $op) {
            foreach ([new ErrorDeRed('timeout'), TransporteFalso::json(503, ['success' => false, 'message' => 'caído'])] as $falla) {
                $t = new TransporteFalso([$falla, self::emisionOk()]);
                try {
                    $op($this->cliente($t));
                    $this->fail("La operación {$i} no debió reintentarse");
                } catch (FactuSmartError) {
                    $this->assertCount(1, $t->peticiones, "La operación {$i} se reintentó");
                }
            }
        }
    }

    public function testLosGetYCrearRucSiSeReintentan(): void
    {
        $t = new TransporteFalso([new ErrorDeRed('x'), TransporteFalso::json(502, []), TransporteFalso::json(200, ['success' => true])]);
        $this->cliente($t)->comprobantes->consultar('abc');
        $this->assertCount(3, $t->peticiones);

        $t = new TransporteFalso([TransporteFalso::json(504, []), TransporteFalso::json(201, ['success' => true, 'ya_existia' => false])]);
        $this->cliente($t)->rucs->crear(['ruc' => '20609999991']);
        $this->assertCount(2, $t->peticiones);
    }

    public function testSeAgotanLosReintentos(): void
    {
        $t = new TransporteFalso(array_fill(0, 4, TransporteFalso::json(503, ['success' => false, 'message' => 'caído'])));
        try {
            $this->cliente($t)->ping();
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertCount(4, $t->peticiones, '1 intento + 3 reintentos');
    }

    public function testDefaultsDeEmitirYEnvioDelRuc(): void
    {
        $t = new TransporteFalso([self::emisionOk(), self::emisionOk(), self::emisionOk()]);
        $c = $this->cliente($t);

        $c->comprobantes->emitir(['codigo_tipo_documento' => '03']);
        $cuerpo = json_decode((string) $t->peticiones[0]['cuerpo'], true);
        $this->assertSame('POST', $t->peticiones[0]['metodo']);
        $this->assertSame('https://ejemplo.test/api/v1/issuer/documents', $t->peticiones[0]['url']);
        $this->assertSame('k', $t->peticiones[0]['cabeceras']['X-API-Key']);
        $this->assertSame('20100070970', $cuerpo['ruc']);
        $this->assertSame('#', $cuerpo['numero_documento']);
        $this->assertSame('0101', $cuerpo['codigo_tipo_operacion']);
        $this->assertSame('PEN', $cuerpo['codigo_tipo_moneda']);
        $lima = new \DateTimeImmutable('now', new \DateTimeZone('America/Lima'));
        $this->assertSame($lima->format('Y-m-d'), $cuerpo['fecha_de_emision']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $cuerpo['hora_de_emision']);

        // Lo que manda el usuario se respeta; opciones['ruc'] sobreescribe al del constructor.
        $c->comprobantes->emitir(['ruc' => '20999999999', 'numero_documento' => '15', 'serie_documento' => 'BA01', 'codigo_tipo_documento' => '03'], ['ruc' => '20111111111']);
        $cuerpo = json_decode((string) $t->peticiones[1]['cuerpo'], true);
        $this->assertSame('20999999999', $cuerpo['ruc']);
        $this->assertSame('15', $cuerpo['numero_documento']);

        // Notas (07/08): no se fuerza codigo_tipo_operacion (solo aplica a 01 y 03).
        $c->comprobantes->emitir(['codigo_tipo_documento' => '07'], ['ruc' => '20111111111']);
        $cuerpo = json_decode((string) $t->peticiones[2]['cuerpo'], true);
        $this->assertArrayNotHasKey('codigo_tipo_operacion', $cuerpo);
        $this->assertSame('20111111111', $cuerpo['ruc']);

        $t2 = new TransporteFalso([self::emisionOk(), self::emisionOk()]);
        $c2 = $this->cliente($t2);
        $c2->comprobantes->emitir(['codigo_tipo_documento' => '01']);
        $c2->comprobantes->emitir(['codigo_tipo_documento' => '08']);
        $this->assertSame('0101', json_decode((string) $t2->peticiones[0]['cuerpo'], true)['codigo_tipo_operacion']);
        $this->assertArrayNotHasKey('codigo_tipo_operacion', json_decode((string) $t2->peticiones[1]['cuerpo'], true));
    }

    public function testRucVaEnLaQueryEnLosGet(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, ['success' => true]), TransporteFalso::json(200, ['success' => true]), new Respuesta(200, [], '%PDF-1.4')]);
        $c = $this->cliente($t);
        $c->comprobantes->listar(['estado' => '01', 'limite' => 5]);
        $this->assertSame('https://ejemplo.test/api/v1/issuer/documents?estado=01&limite=5&ruc=20100070970', $t->peticiones[0]['url']);
        $c->cuenta->saldo();
        $this->assertSame('https://ejemplo.test/api/v1/issuer/account', $t->peticiones[1]['url']);
        $this->assertSame('%PDF-1.4', $c->comprobantes->descargar('a/b', 'PDF', ['ruc' => '20111111111']));
        $this->assertSame('https://ejemplo.test/api/v1/issuer/documents/a%2Fb/pdf?ruc=20111111111', $t->peticiones[2]['url']);
    }

    public function testDescargarRechazaTipoInvalidoYEl404HtmlEsNoEncontrado(): void
    {
        $t = new TransporteFalso([new Respuesta(404, ['content-type' => 'text/html'], '<html>No existe</html>')]);
        $c = $this->cliente($t);
        try {
            $c->comprobantes->descargar('abc', 'zip');
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertStringContainsString('pdf, xml o cdr', $e->mensaje);
        }
        $this->expectException(NoEncontrado::class);
        $c->comprobantes->descargar('abc', 'cdr');
    }

    public function testConstructorExigeApiKeyYBaseUrl(): void
    {
        foreach ([['baseUrl' => 'https://x'], ['apiKey' => 'k'], ['apiKey' => 'k', 'baseUrl' => 's2.factusmart.pe']] as $opciones) {
            try {
                new Cliente($opciones);
                $this->fail('Debió lanzar');
            } catch (FactuSmartError $e) {
                $this->assertMatchesRegularExpression('/apiKey|baseUrl/', $e->mensaje);
            }
        }
    }
}
