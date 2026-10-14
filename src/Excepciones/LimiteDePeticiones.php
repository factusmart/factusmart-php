<?php

declare(strict_types=1);

namespace FactuSmart\Excepciones;

/**
 * Límite de peticiones superado (120/min por API key, 60/min por IP).
 *
 * Ojo: hoy el API responde HTTP 500 con "Too Many Attempts." en vez de 429;
 * el SDK reconoce ambos casos. $segundosDeEspera es null si no se pudo leer.
 */
class LimiteDePeticiones extends FactuSmartError
{
    public ?int $segundosDeEspera;

    /**
     * @param array<mixed>|string|null $respuesta
     */
    public function __construct(string $mensaje, int $status = 429, $respuesta = null, ?int $segundosDeEspera = null, ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, $status, $respuesta, $previa);
        $this->segundosDeEspera = $segundosDeEspera;
    }
}
