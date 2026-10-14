<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/** Comunicación de baja (asíncrona, con ticket). */
final class Anulaciones extends Recurso
{
    /**
     * POST /anulaciones — cuesta 1 crédito. NUNCA se reintenta automáticamente
     * (el API no acepta Idempotency-Key aquí: un reintento podría enviar dos bajas).
     *
     * @param array<string, mixed> $datos    fecha_de_emision_de_documentos (DD-MM-AAAA), documentos[]
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function crear(array $datos, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('POST', '/anulaciones', [], $this->conRuc($datos, $opciones), reintentable: false);
    }

    /**
     * GET /anulaciones/{ticket}/status
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function estado(string $ticket, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/anulaciones/' . self::segmento($ticket, 'ticket') . '/status', $this->queryRuc($opciones));
    }
}
