<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Http\ErrorDeRed;
use PHPUnit\Framework\TestCase;

/** Sin red: configuración del RUC por API (certificado, SOL/entorno, verificar, ver configuración). */
final class ConfiguracionRucTest extends TestCase
{
    private const BASE = 'https://ejemplo.test/api/v1/issuer';
    private const RUC = '20777666555';
    private const CLAVE = 'ClaveDelPfx-9x!';
    private const CLAVE_SOL = 'ClaveSol/"9"';

    /** Bytes que imitan un .pfx (DER: empieza con 0x30 y trae bytes nulos). */
    private const PFX = "\x30\x82\x0A\x5E\x02\x01\x03\x30\x82\x0A\x24\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x01\xA0\x00\x00\xFFcontenido-secreto";

    /** @var array<int, float> */
    private array $esperas = [];

    /** @var array<int, string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $f) {
            @unlink($f);
        }
    }

    private function cliente(TransporteFalso $t): Cliente
    {
        $this->esperas = [];
        return new Cliente([
            'apiKey' => 'k',
            'baseUrl' => self::BASE,
            'ruc' => '20100070970', // RUC por defecto distinto: NO debe usarse en estas llamadas
            'transporte' => $t,
            'dormir' => function (float $s): void {
                $this->esperas[] = $s;
            },
        ]);
    }

    private function archivoTemporal(string $bytes): string
    {
        $f = tempnam(sys_get_temp_dir(), 'pfx');
        file_put_contents($f, $bytes);
        $this->temporales[] = $f;
        return $f;
    }

    /** @param array{metodo: string, url: string, cabeceras: array<string, string>, cuerpo: ?string} $p */
    private function sinRucExtra(array $p): void
    {
        $this->assertArrayNotHasKey('X-RUC', $p['cabeceras']);
        $this->assertStringNotContainsString('?', $p['url'], 'sin query (ni ?ruc=)');
        $this->assertStringNotContainsString('20100070970', $p['url'] . ($p['cuerpo'] ?? ''), 'no usa el RUC por defecto');
    }

    private static function respuestaCertificado(): array
    {
        return ['success' => true, 'ruc' => self::RUC, 'certificado' => [
            'instalado' => true, 'titular' => 'API TENANT CLEAN SAC', 'vence' => '2027-11-10',
            'dias_para_vencer' => 364, 'ruc_coincide' => true,
        ]];
    }

    private static function respuestaConfig(): array
    {
        return ['success' => true, 'ruc' => self::RUC, 'nombre' => 'API TENANT CLEAN SAC', 'entorno' => 'produccion',
            'certificado' => ['instalado' => true, 'vence' => '2027-11-10', 'dias_para_vencer' => 364],
            'sol' => ['configurado' => true, 'usuario' => 'FA*****1'], 'envio' => 'sunat',
            'listo_para_produccion' => true, 'faltantes' => []];
    }

    public function testSubirCertificadoDesdeArchivo(): void
    {
        $ruta = $this->archivoTemporal(self::PFX);
        $t = new TransporteFalso([TransporteFalso::json(200, self::respuestaCertificado())]);
        $r = $this->cliente($t)->rucs->subirCertificado(self::RUC, $ruta, self::CLAVE);

        $p = $t->peticiones[0];
        $this->assertSame('PUT', $p['metodo']);
        $this->assertSame(self::BASE . '/rucs/' . self::RUC . '/certificado', $p['url']);
        $this->assertSame('application/json', $p['cabeceras']['Content-Type']);
        $this->sinRucExtra($p);
        $this->assertSame(['certificado' => base64_encode(self::PFX), 'clave' => self::CLAVE], json_decode((string) $p['cuerpo'], true));

        $this->assertTrue($r['certificado']['ruc_coincide']);
        $this->assertSame('2027-11-10', $r['certificado']['vence']);
    }

    public function testSubirCertificadoDesdeBytes(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, self::respuestaCertificado() + ['aviso' => 'No se encontró un RUC en el titular.'])]);
        $r = $this->cliente($t)->rucs->subirCertificado(self::RUC, self::PFX, self::CLAVE);

        $cuerpo = json_decode((string) $t->peticiones[0]['cuerpo'], true);
        $this->assertSame(self::PFX, base64_decode($cuerpo['certificado'], true));
        $this->assertSame('No se encontró un RUC en el titular.', $r['aviso']);

        // Bytes sin 0x30 al inicio pero con nulos o no-UTF-8 también cuentan como bytes.
        $t = new TransporteFalso([TransporteFalso::json(200, self::respuestaCertificado()), TransporteFalso::json(200, self::respuestaCertificado())]);
        $c = $this->cliente($t);
        $c->rucs->subirCertificado(self::RUC, "abc\0def", self::CLAVE);
        $c->rucs->subirCertificado(self::RUC, "abc\xFF\xFEdef", self::CLAVE);
        $this->assertSame(base64_encode("abc\0def"), json_decode((string) $t->peticiones[0]['cuerpo'], true)['certificado']);
        $this->assertSame(base64_encode("abc\xFF\xFEdef"), json_decode((string) $t->peticiones[1]['cuerpo'], true)['certificado']);
    }

    public function testRutaInexistenteFallaSinPeticionNiClave(): void
    {
        $t = new TransporteFalso([]);
        try {
            $this->cliente($t)->rucs->subirCertificado(self::RUC, 'C:/no/existe/cert.pfx', self::CLAVE);
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertStringContainsString('No se encontró el archivo del certificado', $e->getMessage());
            $this->assertStringContainsString('cert.pfx', $e->getMessage());
            $this->assertStringNotContainsString(self::CLAVE, $e->getMessage());
            $this->assertStringNotContainsString(self::CLAVE, $e->getTraceAsString());
        }
        $this->assertCount(0, $t->peticiones);
    }

    public function testValidacionesLocalesSinPeticion(): void
    {
        $t = new TransporteFalso([]);
        $c = $this->cliente($t);
        foreach ([
            fn () => $c->rucs->subirCertificado('2077766655', self::PFX, self::CLAVE),   // 10 dígitos
            fn () => $c->rucs->subirCertificado(self::RUC, '', self::CLAVE),
            fn () => $c->rucs->subirCertificado(self::RUC, self::PFX, ''),
            fn () => $c->rucs->configurarSunat(self::RUC, []),
            fn () => $c->rucs->configurarSunat(self::RUC, ['entorno' => 'real']),
            fn () => $c->rucs->verificar('abc'),
            fn () => $c->rucs->configuracion('207776665551'),
        ] as $i => $llamada) {
            try {
                $llamada();
                $this->fail("Caso {$i}: debió lanzar");
            } catch (FactuSmartError $e) {
                $this->assertSame(0, $e->status, "Caso {$i}");
            }
        }
        $this->assertCount(0, $t->peticiones);
    }

    public function testConfigurarSunat(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, self::respuestaConfig())]);
        $r = $this->cliente($t)->rucs->configurarSunat(self::RUC, [
            'usuario_sol' => 'FACTURA1', 'clave_sol' => self::CLAVE_SOL, 'entorno' => 'produccion', 'otro' => 'x',
        ]);

        $p = $t->peticiones[0];
        $this->assertSame('PUT', $p['metodo']);
        $this->assertSame(self::BASE . '/rucs/' . self::RUC . '/sunat', $p['url']);
        $this->sinRucExtra($p);
        $this->assertSame(['usuario_sol' => 'FACTURA1', 'clave_sol' => self::CLAVE_SOL, 'entorno' => 'produccion'], json_decode((string) $p['cuerpo'], true));
        $this->assertSame('FA*****1', $r['sol']['usuario']);
        $this->assertSame([], $r['faltantes']);
    }

    public function testVerificarEsPostSinCuerpoYSeReintenta(): void
    {
        $ok = ['success' => true, 'ruc' => self::RUC, 'listo_para_emitir' => false, 'entorno' => 'produccion', 'checks' => [
            'certificado' => ['ok' => true, 'mensaje' => 'Certificado vigente.'],
            'sol' => ['ok' => false, 'mensaje' => 'SUNAT rechazó el usuario o la clave SOL.'],
        ]];
        $t = new TransporteFalso([
            TransporteFalso::json(503, ['success' => false, 'message' => 'Mantenimiento.', 'reintentable' => true]),
            TransporteFalso::json(200, $ok),
        ]);
        $r = $this->cliente($t)->rucs->verificar(self::RUC);

        $p = $t->peticiones[0];
        $this->assertSame('POST', $p['metodo']);
        $this->assertSame(self::BASE . '/rucs/' . self::RUC . '/verificar', $p['url']);
        $this->assertNull($p['cuerpo']);
        $this->assertArrayNotHasKey('Content-Type', $p['cabeceras']);
        $this->sinRucExtra($p);
        $this->assertCount(2, $t->peticiones);
        $this->assertFalse($r['listo_para_emitir']);
        $this->assertFalse($r['checks']['sol']['ok']);
    }

    public function testConfiguracionEsGet(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(200, self::respuestaConfig())]);
        $r = $this->cliente($t)->rucs->configuracion(' ' . self::RUC . ' ');

        $p = $t->peticiones[0];
        $this->assertSame('GET', $p['metodo']);
        $this->assertSame(self::BASE . '/rucs/' . self::RUC . '/configuracion', $p['url']);
        $this->assertNull($p['cuerpo']);
        $this->sinRucExtra($p);
        $this->assertSame('sunat', $r['envio']);
        $this->assertTrue($r['listo_para_produccion']);
    }

    public function testPutsNoSeReintentanSolos(): void
    {
        $t = new TransporteFalso([TransporteFalso::json(503, ['success' => false, 'message' => 'x']), TransporteFalso::json(200, self::respuestaConfig())]);
        try {
            $this->cliente($t)->rucs->configurarSunat(self::RUC, ['entorno' => 'prueba']);
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertCount(1, $t->peticiones);
    }

    /** El API (o un proxy) devuelve lo enviado en el error: el SDK lo tapa antes de crear la excepción. */
    public function testUn422ConEcoDeLaClaveNoLaFiltra(): void
    {
        $base64 = base64_encode(self::PFX);
        $eco = 'Clave ' . self::CLAVE . ' incorrecta para ' . $base64;
        $t = new TransporteFalso([TransporteFalso::json(422, [
            'success' => false, 'message' => $eco, 'codigo' => 'DATOS_INVALIDOS', 'reintentable' => false,
            'campos' => ['clave' => [$eco]], 'recibido' => ['clave' => self::CLAVE, 'certificado' => $base64],
        ])]);
        try {
            $this->cliente($t)->rucs->subirCertificado(self::RUC, self::PFX, self::CLAVE);
            $this->fail('Debió lanzar ErrorDeValidacion');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('DATOS_INVALIDOS', $e->codigo);
            $todo = $e->getMessage() . $e->mensaje . json_encode($e->respuesta) . json_encode($e->campos)
                . json_encode($e->errores) . $e->getTraceAsString() . print_r($e, true) . var_export($e->respuesta, true);
            $this->assertStringNotContainsString(self::CLAVE, $todo);
            $this->assertStringNotContainsString($base64, $todo);
            $this->assertStringNotContainsString('contenido-secreto', $todo);
            $this->assertStringContainsString('Clave *** incorrecta para ***', $e->getMessage());
            $this->assertSame(['clave' => ['Clave *** incorrecta para ***']], $e->campos);
        }
    }

    public function testClaveSolConCaracteresEscapadosEnJsonTampocoSeFiltra(): void
    {
        // Sin JSON_UNESCAPED_SLASHES el eco trae "ClaveSol\/\"9\"": también debe taparse.
        $cuerpo = '{"success":false,"message":"SOL ' . substr((string) json_encode(self::CLAVE_SOL), 1, -1) . ' rechazado","codigo":"DATOS_INVALIDOS"}';
        $t = new TransporteFalso([new \FactuSmart\Http\Respuesta(422, ['content-type' => 'application/json'], $cuerpo)]);
        try {
            $this->cliente($t)->rucs->configurarSunat(self::RUC, ['usuario_sol' => 'FACTURA1', 'clave_sol' => self::CLAVE_SOL]);
            $this->fail('Debió lanzar');
        } catch (ErrorDeValidacion $e) {
            $this->assertSame('SOL *** rechazado', $e->getMessage());
            $this->assertStringNotContainsString(self::CLAVE_SOL, print_r($e, true) . $e->getTraceAsString());
            $escapada = substr((string) json_encode(self::CLAVE_SOL), 1, -1);
            $this->assertStringNotContainsString($escapada, json_encode($e->respuesta) . print_r($e, true));
            $this->assertStringNotContainsString(self::CLAVE_SOL, json_encode($e->respuesta, JSON_UNESCAPED_SLASHES));
        }
    }

    public function testErrorDeRedNoFiltraNiLaClaveNiElCuerpo(): void
    {
        $t = new TransporteFalso([new ErrorDeRed('timeout enviando clave=' . self::CLAVE)]);
        try {
            $this->cliente($t)->rucs->subirCertificado(self::RUC, self::PFX, self::CLAVE);
            $this->fail('Debió lanzar');
        } catch (FactuSmartError $e) {
            $this->assertStringContainsString('No se pudo conectar', $e->getMessage());
            $this->assertStringNotContainsString(self::CLAVE, $e->getMessage() . print_r($e, true));
            $this->assertNull($e->getPrevious());
        }
        $this->assertCount(1, $t->peticiones, 'PUT del certificado: no se reintenta');
    }

    public function testContratoMapeaLasCuatroOperaciones(): void
    {
        $this->assertSame(['rucs', 'subirCertificado'], Cliente::OPERACIONES['uploadRucCertificate']);
        $this->assertSame(['rucs', 'configurarSunat'], Cliente::OPERACIONES['updateRucSunat']);
        $this->assertSame(['rucs', 'verificar'], Cliente::OPERACIONES['verifyRucConfig']);
        $this->assertSame(['rucs', 'configuracion'], Cliente::OPERACIONES['getRucConfig']);
    }
}
