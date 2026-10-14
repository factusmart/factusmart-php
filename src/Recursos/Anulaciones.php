<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

/**
 * Anulaciones (asíncronas, con ticket): facturas por comunicación de baja y boletas por resumen diario.
 * El API elige el camino; el SDK no cambia de método.
 */
final class Anulaciones extends Recurso
{
    /**
     * POST /anulaciones — cuesta 1 crédito (gratis en RUCs demo). NUNCA se reintenta automáticamente
     * (el API no acepta Idempotency-Key aquí: un reintento podría enviar dos bajas).
     *
     * Facturas (y sus notas) → `tipo: baja`, `identificador` RA-AAAAMMDD-N.
     * Boletas (y sus notas; solo aceptadas, estado 05) → `tipo: resumen`, `identificador` RC-AAAAMMDD-N.
     * Facturas y boletas juntas → ErrorDeValidacion con codigo ANULACION_MIXTA: una petición por tipo.
     * La respuesta trae además ticket, external_id, creditos_restantes, credito_cobrado
     * (y en demo modo_demo, pruebas_restantes_hoy).
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
     * GET /anulaciones/{ticket}/status — trae también `tipo` y `documentos[]`.
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function estado(string $ticket, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/anulaciones/' . self::segmento($ticket, 'ticket') . '/status', $this->queryRuc($opciones));
    }
}
