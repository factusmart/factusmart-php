<?php

declare(strict_types=1);

namespace FactuSmart\Pruebas\Unidad;

use FactuSmart\Http\ErrorDeRed;
use FactuSmart\Http\Respuesta;
use FactuSmart\Http\Transporte;

/**
 * Transporte en memoria: devuelve respuestas programadas y guarda cada petición recibida.
 * Un elemento de la cola puede ser una Respuesta o un ErrorDeRed (simula caída de red).
 */
final class TransporteFalso implements Transporte
{
    /** @var array<int, array{metodo: string, url: string, cabeceras: array<string, string>, cuerpo: ?string}> */
    public array $peticiones = [];

    /** @param array<int, Respuesta|ErrorDeRed> $cola */
    public function __construct(private array $cola)
    {
    }

    public static function json(int $status, array $cuerpo, array $cabeceras = []): Respuesta
    {
        return new Respuesta($status, $cabeceras + ['content-type' => 'application/json'], (string) json_encode($cuerpo));
    }

    public function enviar(string $metodo, string $url, array $cabeceras, ?string $cuerpo, int $timeout, bool $verificarSsl): Respuesta
    {
        $this->peticiones[] = compact('metodo', 'url', 'cabeceras', 'cuerpo');
        if ($this->cola === []) {
            throw new \LogicException('TransporteFalso: no quedan respuestas programadas.');
        }
        $siguiente = array_shift($this->cola);
        if ($siguiente instanceof ErrorDeRed) {
            throw $siguiente;
        }
        return $siguiente;
    }
}
