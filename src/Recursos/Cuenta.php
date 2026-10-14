<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** Estado de la cuenta API. */
final class Cuenta extends Recurso
{
    /**
     * GET /account — saldo (creditos_disponibles), plan y RUCs. No requiere RUC.
     * @return array<string, mixed>
     */
    public function saldo(): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/account');
    }
}
