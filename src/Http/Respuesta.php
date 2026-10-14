<?php

declare(strict_types=1);

namespace FactuSmart\Http;

/** Respuesta HTTP cruda. Las cabeceras van con el nombre en minúsculas. */
final class Respuesta
{
    /**
     * @param array<string, string> $cabeceras
     */
    public function __construct(
        public readonly int $status,
        public readonly array $cabeceras,
        public readonly string $cuerpo
    ) {
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }
}
