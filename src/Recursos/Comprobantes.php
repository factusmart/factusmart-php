<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\FactuSmartError;

/** Facturas (01), boletas (03), notas de crédito (07) y débito (08). */
final class Comprobantes extends Recurso
{
    private const TIPOS_ARCHIVO = ['pdf', 'xml', 'cdr'];

    /**
     * POST /documents — emite el comprobante. Cuesta 1 crédito.
     *
     * Siempre manda Idempotency-Key (la tuya en $opciones['idempotencyKey'] o un UUID v4)
     * y la reusa en todos los reintentos: un reintento nunca emite dos veces.
     *
     * Ojo: HTTP 200 NO significa aceptado. Revisa $r['aceptado_por_sunat'].
     *
     * @param array<string, mixed> $datos    payload tal cual lo define el API
     * @param array<string, mixed> $opciones ruc, idempotencyKey
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
        $tipo = (string) ($datos['codigo_tipo_documento'] ?? '');
        // El catálogo 51 (tipo de operación) solo aplica a facturas y boletas (ver openapi.yaml).
        if (!isset($datos['codigo_tipo_operacion']) && in_array($tipo, ['01', '03'], true)) {
            $datos['codigo_tipo_operacion'] = '0101';
        }
        $datos['codigo_tipo_moneda'] ??= 'PEN';

        $clave = (string) ($opciones['idempotencyKey'] ?? $opciones['idempotency_key'] ?? '');
        if ($clave === '') {
            $clave = self::uuid4();
        }

        /** @var array<string, mixed> */
        return $this->http->peticion(
            'POST',
            '/documents',
            [],
            $datos,
            ['Idempotency-Key' => $clave],
            reintentable: true,
            reintentar409: true
        );
    }

    /**
     * GET /documents — lista del más reciente al más antiguo.
     * @param array<string, mixed> $filtros  estado, desde, hasta, limite, antes_de
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function listar(array $filtros = [], array $opciones = []): array
    {
        $query = $filtros;
        $query['ruc'] = $this->ruc($opciones + $filtros);
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/documents', $query);
    }

    /**
     * GET /documents/{external_id} — estado guardado (no consulta a SUNAT en vivo).
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function consultar(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/documents/' . self::segmento($externalId, 'externalId'), $this->queryRuc($opciones));
    }

    /**
     * GET /documents/{external_id}/{type} — devuelve los BYTES del archivo (string binario).
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
            '/documents/' . self::segmento($externalId, 'externalId') . '/' . $tipo,
            $this->queryRuc($opciones),
            binario: true
        );
    }

    /**
     * POST /documents/{external_id}/reenviar — reintenta el envío a SUNAT. No se reintenta solo.
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function reenviar(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion(
            'POST',
            '/documents/' . self::segmento($externalId, 'externalId') . '/reenviar',
            [],
            $this->conRuc([], $opciones),
            reintentable: false
        );
    }

    /**
     * POST /documents/{external_id}/consultar-sunat — pregunta a SUNAT en vivo. No se reintenta solo.
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function consultarSunat(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion(
            'POST',
            '/documents/' . self::segmento($externalId, 'externalId') . '/consultar-sunat',
            [],
            $this->conRuc([], $opciones),
            reintentable: false
        );
    }

    /**
     * GET /documents/{external_id}/historial — línea de tiempo del comprobante.
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function historial(string $externalId, array $opciones = []): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/documents/' . self::segmento($externalId, 'externalId') . '/historial', $this->queryRuc($opciones));
    }

    /** UUID v4 aleatorio (para Idempotency-Key). */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
