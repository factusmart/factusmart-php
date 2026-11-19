<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\FactuSmartError;

/** Guías de remisión electrónica GRE 2.0 (asíncronas). */
final class Guias extends Recurso
{
    private const TIPOS_ARCHIVO = ['pdf', 'xml', 'cdr'];

    /**
     * POST /guias — NUNCA se reintenta automáticamente (no usa Idempotency-Key).
     * Revisa $r['enviado']: solo si es true se cobró el crédito.
     * $r['envio']['links'] trae las URLs de descarga (`xml`, `pdf`, `cdr`).
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
     * GET /guias/{external_id}/status — trae `links` (`xml`, `pdf`, `cdr`): URLs de descarga.
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function estado(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/guias/' . self::segmento($externalId, 'externalId') . '/status', $this->queryRuc($opciones));
    }

    /**
     * POST /guias/{external_id}/reenviar — reintenta enviar a SUNAT una guía que quedó en `01` (Registrado).
     * NUNCA se reintenta solo. Cobra 1 crédito solo si el envío sale bien. Trae `serie_numero`, `external_id`,
     * `enviado`, `estado`, `mensaje`, `links` (`xml`, `pdf`, `cdr`), `envio` y el resumen del crédito.
     * Envío fallido (no se cobra) o guía que no está en `01` (trae `estado`): ErrorDeValidacion con codigo
     * `REENVIO_FALLIDO`. Guía inexistente: NoEncontrado. Luego consulta estado().
     *
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function reenviar(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion(
            'POST',
            '/guias/' . self::segmento($externalId, 'externalId') . '/reenviar',
            [],
            $this->conRuc([], $opciones),
            reintentable: false
        );
    }

    /**
     * GET /guias/{external_id}/{type} — devuelve los BYTES del archivo (string binario): el PDF (A4),
     * el XML firmado o el CDR de la guía. El CDR solo existe cuando SUNAT la aceptó; si falta, lanza
     * NoEncontrado (codigo NO_ENCONTRADO): consulta estado() y vuelve a intentar.
     *
     * @param string $tipo pdf | xml | cdr
     * @param array<string, mixed> $opciones ruc
     */
    public function descargar(string $externalId, string $tipo, array $opciones = []): string
    {
        $tipo = strtolower($tipo);
        if (!in_array($tipo, self::TIPOS_ARCHIVO, true)) {
            throw new FactuSmartError("Tipo de archivo inválido: '{$tipo}'. Usa pdf, xml o cdr.");
        }
        /** @var string */
        return $this->http->peticion(
            'GET',
            '/guias/' . self::segmento($externalId, 'externalId') . '/' . $tipo,
            $this->queryRuc($opciones),
            binario: true
        );
    }
}
