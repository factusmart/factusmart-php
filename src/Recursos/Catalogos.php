<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** Catálogos SUNAT (unidades, afectación del IGV, monedas...). No consumen crédito. */
final class Catalogos extends Recurso
{
    /**
     * GET /catalogos — lista los catálogos que se pueden consultar (`catalogos`, `nota`).
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function listar(array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/catalogos', $this->queryRuc($opciones));
    }

    /**
     * GET /catalogos/{nombre} — filas de un catálogo (`catalogo`, `total`, `datos`).
     *
     * $nombre es el nombre (`unidades`) o el número SUNAT (`03`, `07`...).
     * Usa solo las filas con `activo: true`: el RUC valida contra ellas al emitir.
     *
     * @param array<string, mixed> $opciones q (filtro por código o descripción), ruc
     * @return array<string, mixed>
     */
    public function consultar(string $nombre, array $opciones = []): array
    {
        $query = $this->queryRuc($opciones);
        if (isset($opciones['q']) && $opciones['q'] !== '') {
            $query = ['q' => (string) $opciones['q']] + $query;
        }
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/catalogos/' . self::segmento($nombre, 'nombre'), $query);
    }
}
