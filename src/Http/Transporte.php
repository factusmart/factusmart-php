<?php

declare(strict_types=1);

namespace FactuSmart\Http;

/**
 * Lo único que el SDK necesita para hablar por la red. La implementación por
 * defecto es TransporteCurl; en pruebas se puede inyectar uno falso.
 */
interface Transporte
{
    /**
     * @param array<string, string> $cabeceras
     * @throws ErrorDeRed si no hubo respuesta HTTP (DNS, conexión, timeout, SSL...)
     */
    public function enviar(
        string $metodo,
        string $url,
        array $cabeceras,
        ?string $cuerpo,
        int $timeout,
        bool $verificarSsl
    ): Respuesta;
}
