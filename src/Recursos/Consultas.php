<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\ErrorDeValidacion;

/**
 * Consulta de RUC y DNI (nombre, dirección, estado, condición, ubigeo) para llenar los datos del
 * cliente antes de emitir. No emite, no gasta créditos y NO necesita RUC emisor: nunca manda `ruc`.
 *
 * Tiene su propio cupo por cuenta (`consultas_restantes`, `periodo` mes|dia, `se_renueva`). Al
 * agotarlo: 429 `LIMITE_CONSULTAS` (`reintentable: false`, no se repite). `503 CONSULTA_NO_DISPONIBLE`
 * sí se reintenta, como cualquier GET.
 */
final class Consultas extends Recurso
{
    public const TIPOS = ['ruc', 'dni'];

    /**
     * GET /consultas/ruc/{numero}
     * @return array<string, mixed> success, tipo, numero, nombre, direccion, estado, condicion, ubigeo,
     *                              desde_cache, consultas_restantes, periodo, se_renueva
     */
    public function consultarRuc(string $numero): array
    {
        return $this->consultar('ruc', $numero);
    }

    /**
     * GET /consultas/dni/{numero} — del DNI casi siempre solo llega `nombre` (lo demás null).
     * @return array<string, mixed>
     */
    public function consultarDni(string $numero): array
    {
        return $this->consultar('dni', $numero);
    }

    /**
     * GET /consultas/{tipo}/{numero} — `tipo` = `ruc` | `dni`. Otro tipo lanza ErrorDeValidacion
     * (422, DATOS_INVALIDOS, campos['tipo']) sin hacer la petición. El número lo valida el servidor.
     * @return array<string, mixed>
     */
    public function consultar(string $tipo, string $numero): array
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, self::TIPOS, true)) {
            $mensaje = "Tipo de documento inválido: '{$tipo}'. Usa ruc o dni.";
            $campos = ['tipo' => ['El tipo debe ser ruc o dni.']];
            throw new ErrorDeValidacion($mensaje, 422, [
                'success' => false,
                'message' => $mensaje,
                'codigo' => 'DATOS_INVALIDOS',
                'reintentable' => false,
                'campos' => $campos,
            ], $campos);
        }
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/consultas/' . $tipo . '/' . self::segmento(trim($numero), 'numero'));
    }
}
