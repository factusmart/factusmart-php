<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\FactuSmartError;

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

    /**
     * GET /catalogos/productos-sunat — busca el código de producto SUNAT (catálogo 25) por código
     * exacto/prefijo o por texto (sin tildes). Respuesta: `catalogo`, `catalogo_sunat` ("25"), `q`,
     * `total`, `hay_mas`, `datos` [{codigo, descripcion, ...}]. Se reintenta como cualquier GET.
     *
     * @param array<string, mixed> $opciones limite (máx. 50), nivel (segmento|familia|clase|producto), ruc
     * @return array<string, mixed>
     */
    public function buscarProductoSunat(string $q, array $opciones = []): array
    {
        if (trim($q) === '') {
            throw new FactuSmartError('Falta q: el texto o código a buscar.');
        }
        $query = ['q' => $q];
        if (isset($opciones['limite']) && $opciones['limite'] !== '') {
            $query['limite'] = (int) $opciones['limite'];
        }
        if (isset($opciones['nivel']) && $opciones['nivel'] !== '') {
            $query['nivel'] = (string) $opciones['nivel']; // segmento | familia | clase | producto
        }
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/catalogos/productos-sunat', $query + $this->queryRuc($opciones));
    }
}
