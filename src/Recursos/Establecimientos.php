<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** Locales (anexos SUNAT) del RUC, con sus series. No consumen crédito. */
final class Establecimientos extends Recurso
{
    /**
     * GET /establecimientos — locales del RUC (`establecimientos[]`, cada uno con `codigo_anexo`,
     * `predeterminado` y sus `series`). Se reintenta como cualquier GET.
     *
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function listar(array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/establecimientos', $this->queryRuc($opciones));
    }

    /**
     * POST /establecimientos — crea un local (y su almacén y series). NUNCA se reintenta solo:
     * no es idempotente. Regístralo antes en SUNAT (Clave SOL). Límite del plan →
     * RucNoAutorizado con `codigo` LIMITE_ESTABLECIMIENTOS.
     *
     * @param array<string, mixed> $datos    codigo_anexo, nombre, direccion, ubigeo y opcionales
     *                                       telefono, email, crear_series_por_defecto, series[]
     * @param array<string, mixed> $opciones ruc (va en el cuerpo si $datos no lo trae)
     * @return array<string, mixed> success, message, establecimiento
     */
    public function crear(array $datos, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('POST', '/establecimientos', [], $this->conRuc($datos, $opciones), reintentable: false);
    }
}
