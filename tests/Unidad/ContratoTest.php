<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Cliente;
use PHPUnit\Framework\TestCase;

/**
 * Contrato: cada operationId del openapi.yaml oficial debe tener su método en el SDK.
 * Si el API crece y nadie actualiza el SDK, esta prueba falla.
 *
 * Lee el yaml de FACTUSMART_OPENAPI (el del repo del API); si no existe en esta máquina,
 * usa la copia en tests/fixtures/openapi.yaml.
 */
final class ContratoTest extends TestCase
{
    private static function rutaYaml(): string
    {
        $env = getenv('FACTUSMART_OPENAPI');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $env;
        }
        return __DIR__ . '/../fixtures/openapi.yaml';
    }

    /** @return array<int, string> */
    private static function operationIds(): array
    {
        $yaml = (string) file_get_contents(self::rutaYaml());
        preg_match_all('/^\s*operationId:\s*[\'"]?([A-Za-z0-9_]+)[\'"]?\s*$/m', $yaml, $m);
        return $m[1];
    }

    public function testTodosLosOperationIdTienenMetodoEnElSdk(): void
    {
        $ids = self::operationIds();
        $this->assertGreaterThanOrEqual(17, count($ids), 'No se pudieron leer los operationId de ' . self::rutaYaml());

        $faltan = array_values(array_diff($ids, array_keys(Cliente::OPERACIONES)));
        $this->assertSame([], $faltan, 'operationId sin método en el SDK: ' . implode(', ', $faltan));

        $cliente = new Cliente(['apiKey' => 'k', 'baseUrl' => 'https://x.test']);
        foreach ($ids as $id) {
            [$recurso, $metodo] = Cliente::OPERACIONES[$id];
            $objeto = $recurso === null ? $cliente : $cliente->{$recurso};
            $this->assertTrue(method_exists($objeto, $metodo), "{$id}: falta el método " . ($recurso ? "{$recurso}->" : '') . "{$metodo}()");
        }
    }

    public function testHerramientasCalcularYCatalogosEstanMapeadas(): void
    {
        $ids = self::operationIds();
        $esperado = [
            'calculateDocument' => ['comprobantes', 'calcular'],
            'listCatalogs' => ['catalogos', 'listar'],
            'getCatalog' => ['catalogos', 'consultar'],
            'searchSunatProductCodes' => ['catalogos', 'buscarProductoSunat'],
            'listEstablishments' => ['establecimientos', 'listar'],
            'createEstablishment' => ['establecimientos', 'crear'],
        ];
        foreach ($esperado as $id => $destino) {
            $this->assertContains($id, $ids, "{$id} no está en " . self::rutaYaml());
            $this->assertSame($destino, Cliente::OPERACIONES[$id]);
        }
    }

    public function testElSdkNoDeclaraOperacionesQueElApiYaNoTiene(): void
    {
        $sobran = array_values(array_diff(array_keys(Cliente::OPERACIONES), self::operationIds()));
        $this->assertSame([], $sobran, 'Métodos del SDK cuyo operationId ya no existe en el API: ' . implode(', ', $sobran));
    }
}
