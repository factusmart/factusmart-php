<?php

declare(strict_types=1);

namespace FactuSmart\Excepciones;

/**
 * HTTP 422: datos inválidos, falta el RUC, o el motor de emisión falló.
 * El crédito NO se cobra.
 *
 * $errores trae el mapa campo => [mensajes] cuando el API lo devuelve
 * (validación Laravel); si no, queda vacío y todo está en $mensaje.
 */
class ErrorDeValidacion extends FactuSmartError
{
    /** @var array<string, array<int, string>> */
    public array $errores;

    /**
     * @param array<mixed>|string|null $respuesta
     * @param array<string, array<int, string>> $errores
     */
    public function __construct(string $mensaje, int $status = 422, $respuesta = null, array $errores = [], ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, $status, $respuesta, $previa);
        $this->errores = $errores;
    }
}
