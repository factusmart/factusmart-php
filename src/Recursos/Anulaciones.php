<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\FactuSmartError;

/**
 * Anulaciones (asíncronas, con ticket): facturas por comunicación de baja y boletas por resumen diario.
 * El API elige el camino; el SDK no cambia de método.
 */
final class Anulaciones extends Recurso
{
    /** Las anulaciones no tienen PDF. */
    private const TIPOS_ARCHIVO = ['xml', 'cdr'];

    /**
     * POST /anulaciones — cuesta 1 crédito (gratis en RUCs demo). NUNCA se reintenta automáticamente
     * (el API no acepta Idempotency-Key aquí: un reintento podría enviar dos bajas).
     *
     * Facturas (y sus notas) → `tipo: baja`, `identificador` RA-AAAAMMDD-N.
     * Boletas (y sus notas; solo aceptadas, estado 05) → `tipo: resumen`, `identificador` RC-AAAAMMDD-N.
     * Facturas y boletas juntas → ErrorDeValidacion con codigo ANULACION_MIXTA: una petición por tipo.
     * La respuesta trae además ticket, external_id, creditos_restantes, credito_cobrado
     * (y en demo modo_demo, pruebas_restantes_hoy) y `links` (`xml`, `cdr`): URLs de descarga.
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
     * GET /anulaciones/{ticket}/status — trae también `tipo`, `documentos[]` y `links` (`xml`, `cdr`).
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function estado(string $ticket, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/anulaciones/' . self::segmento($ticket, 'ticket') . '/status', $this->queryRuc($opciones));
    }

    /**
     * GET /anulaciones/{ticket}/{type} — devuelve los BYTES (string binario) del XML enviado o del CDR
     * (ZIP de SUNAT) de la comunicación de baja o del resumen. Sin PDF. El CDR existe cuando SUNAT ya
     * respondió; si falta, lanza NoEncontrado (codigo NO_ENCONTRADO).
     *
     * @param string $tipo xml | cdr
     * @param array<string, mixed> $opciones ruc
     */
    public function descargar(string $ticket, string $tipo, array $opciones = []): string
    {
        $tipo = strtolower($tipo);
        if (!in_array($tipo, self::TIPOS_ARCHIVO, true)) {
            throw new FactuSmartError("Tipo de archivo inválido: '{$tipo}'. Usa xml o cdr (las anulaciones no tienen PDF).");
        }
        /** @var string */
        return $this->http->peticion(
            'GET',
            '/anulaciones/' . self::segmento($ticket, 'ticket') . '/' . $tipo,
            $this->queryRuc($opciones),
            binario: true
        );
    }
}
