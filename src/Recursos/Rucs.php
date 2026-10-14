<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** RUCs de la cuenta (marca blanca / multi-empresa). Estos endpoints nunca usan el RUC por defecto. */
final class Rucs extends Recurso
{
    /**
     * GET /rucs
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/rucs');
    }

    /**
     * POST /rucs — da de alta un RUC (nace en modo prueba). Es idempotente por RUC
     * en el API (si ya es tuyo devuelve ya_existia=true), por eso sí se reintenta.
     *
     * @param array<string, mixed> $datos ruc, empresa, correo, clave
     * @return array<string, mixed>
     */
    public function crear(array $datos): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('POST', '/rucs', [], $datos, reintentable: true);
    }

    /**
     * PATCH /rucs/{ruc}/activation — activa (true) o desactiva (false). No se reintenta solo.
     * @return array<string, mixed>
     */
    public function activar(string $ruc, bool $activo): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('PATCH', '/rucs/' . self::segmento($ruc, 'ruc') . '/activation', [], ['activo' => $activo], reintentable: false);
    }
}
