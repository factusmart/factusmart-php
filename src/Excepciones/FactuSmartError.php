<?php

declare(strict_types=1);

namespace FactuSmart\Excepciones;

/**
 * Error base de todo el SDK.
 *
 * - $status:       código HTTP (0 si fue un error de red o de configuración).
 * - $mensaje:      el `message` del API, legible y en español.
 * - $respuesta:    cuerpo completo de la respuesta (array si era JSON, string si no; null si no hubo respuesta).
 * - $codigo:       código estable del error en MAYÚSCULAS (ver FactuSmart\Codigos), p.ej. "SIN_CREDITOS".
 *                  null si no hubo respuesta o el servidor es anterior a 0.2.0 y no lo manda.
 * - $reintentable: true si el API dice que repetir la misma petición puede funcionar
 *                  (p.ej. SUNAT_NO_DISPONIBLE). false si no lo dice o el servidor no lo manda.
 * - $campos:       en validación (422), mapa campo => [mensajes]. Vacío si no aplica.
 *
 * Usa $codigo para decidir en tu código, nunca el texto de $mensaje (puede cambiar).
 */
class FactuSmartError extends \RuntimeException
{
    public int $status;
    public string $mensaje;
    /** @var array<mixed>|string|null */
    public $respuesta;

    public readonly ?string $codigo;
    public readonly bool $reintentable;
    /** @var array<string, array<int, string>> */
    public readonly array $campos;

    /**
     * @param array<mixed>|string|null $respuesta
     */
    public function __construct(string $mensaje, int $status = 0, $respuesta = null, ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, $status, $previa);
        $this->status = $status;
        $this->mensaje = $mensaje;
        $this->respuesta = $respuesta;

        $datos = is_array($respuesta) ? $respuesta : [];
        $codigo = $datos['codigo'] ?? null;
        $this->codigo = is_string($codigo) && $codigo !== '' ? $codigo : null;
        $this->reintentable = ($datos['reintentable'] ?? null) === true;
        $this->campos = self::normalizarCampos($datos['campos'] ?? null);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function normalizarCampos(mixed $campos): array
    {
        if (!is_array($campos)) {
            return [];
        }
        $salida = [];
        foreach ($campos as $campo => $lista) {
            if (!is_string($campo)) {
                continue;
            }
            $lista = is_array($lista) ? $lista : [$lista];
            $salida[$campo] = array_values(array_map('strval', array_filter($lista, 'is_scalar')));
        }
        return $salida;
    }
}
