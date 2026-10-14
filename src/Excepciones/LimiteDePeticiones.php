<?php

declare(strict_types=1);

namespace FactuSmart\Excepciones;

/**
 * Límite de peticiones superado (120/min por API key, 60/min por IP).
 *
 * El API responde 429 con codigo LIMITE_PETICIONES; servidores viejos respondían HTTP 500
 * con "Too Many Attempts.". El SDK reconoce todos los casos. $segundosDeEspera es null si no se pudo leer.
 *
 * También el tope diario de los RUCs demo: 429 con $codigo LIMITE_PRUEBAS_DIARIO y
 * $reintentable false (no se reintenta; se reinicia a medianoche de Lima). Decide por
 * $e->codigo / $e->reintentable, no por la clase.
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
