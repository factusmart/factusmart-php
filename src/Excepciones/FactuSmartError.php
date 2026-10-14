<?php

declare(strict_types=1);

namespace FactuSmart\Excepciones;

/**
 * Error base de todo el SDK.
 *
 * - $status:    código HTTP (0 si fue un error de red o de configuración).
 * - $mensaje:   el `message` del API, legible y en español.
 * - $respuesta: cuerpo completo de la respuesta (array si era JSON, string si no; null si no hubo respuesta).
 */
class FactuSmartError extends \RuntimeException
{
    public int $status;
    public string $mensaje;
    /** @var array<mixed>|string|null */
    public $respuesta;

    /**
     * @param array<mixed>|string|null $respuesta
     */
    public function __construct(string $mensaje, int $status = 0, $respuesta = null, ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, $status, $previa);
        $this->status = $status;
        $this->mensaje = $mensaje;
        $this->respuesta = $respuesta;
    }
}
