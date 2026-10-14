<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Http\ClienteHttp;

/** Base de los recursos (comprobantes, guías...). Uso interno. */
abstract class Recurso
{
    public function __construct(
        protected readonly ClienteHttp $http,
        protected readonly ?string $rucPorDefecto
    ) {
    }

    /** @param array<string, mixed> $opciones */
    protected function ruc(array $opciones): ?string
    {
        $ruc = $opciones['ruc'] ?? $this->rucPorDefecto;
        return $ruc === null || $ruc === '' ? null : (string) $ruc;
    }

    /**
     * Query para un GET: solo `ruc` (si hay).
     * @param array<string, mixed> $opciones
     * @return array<string, mixed>
     */
    protected function queryRuc(array $opciones): array
    {
        return ['ruc' => $this->ruc($opciones)];
    }

    /**
     * Cuerpo para POST/PATCH: agrega `ruc` si el usuario no lo puso ya en sus datos.
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $opciones
     * @return array<string, mixed>
     */
    protected function conRuc(array $datos, array $opciones): array
    {
        if (!array_key_exists('ruc', $datos) && !array_key_exists('emisor_ruc', $datos)) {
            $ruc = $this->ruc($opciones);
            if ($ruc !== null) {
                $datos['ruc'] = $ruc;
            }
        }
        return $datos;
    }

    protected static function segmento(string $valor, string $nombre): string
    {
        if (trim($valor) === '') {
            throw new FactuSmartError("Falta {$nombre}.");
        }
        return rawurlencode($valor);
    }
}
