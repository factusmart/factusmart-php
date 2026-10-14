<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** Series del RUC (FA01, BA01, FN01...). */
final class Series extends Recurso
{
    /**
     * GET /series
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function listar(array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/series', $this->queryRuc($opciones));
    }
}
