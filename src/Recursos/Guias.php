<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** Guías de remisión electrónica GRE 2.0 (asíncronas). */
final class Guias extends Recurso
{
    /**
     * POST /guias — NUNCA se reintenta automáticamente (no usa Idempotency-Key).
     * Revisa $r['enviado']: solo si es true se cobró el crédito.
     *
     * @param array<string, mixed> $datos    payload GRE tal cual lo define el API
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function emitir(array $datos, array $opciones = []): array
    {
        $datos = $this->conRuc($datos, $opciones);
        if (!isset($datos['numero_documento']) || $datos['numero_documento'] === '') {
            $datos['numero_documento'] = '#';
        }
        if (empty($datos['fecha_de_emision']) || empty($datos['hora_de_emision'])) {
            $ahora = new \DateTimeImmutable('now', new \DateTimeZone('America/Lima'));
            $datos['fecha_de_emision'] ??= $ahora->format('Y-m-d');
            $datos['hora_de_emision'] ??= $ahora->format('H:i:s');
        }
        /** @var array<string, mixed> */
        return $this->http->peticion('POST', '/guias', [], $datos, reintentable: false);
    }

    /**
     * GET /guias/{external_id}/status
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function estado(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/guias/' . self::segmento($externalId, 'externalId') . '/status', $this->queryRuc($opciones));
    }
}
