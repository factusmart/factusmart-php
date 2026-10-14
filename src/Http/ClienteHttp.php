<?php

declare(strict_types=1);

namespace FactuSmart\Http;

use FactuSmart\Excepciones\ErrorDeAutenticacion;
use FactuSmart\Excepciones\ErrorDeValidacion;
use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Excepciones\LimiteDePeticiones;
use FactuSmart\Excepciones\NoEncontrado;
use FactuSmart\Excepciones\RucNoAutorizado;
use FactuSmart\Excepciones\SinCreditos;

/**
 * Uso interno. Arma la petición, aplica reintentos y convierte errores HTTP en excepciones.
 */
final class ClienteHttp
{
    public const VERSION = '0.2.0';

    /**
     * Estados HTTP que se reintentan (si la operación es reintentable). Además se
     * reintenta cualquier error cuyo cuerpo diga `reintentable: true`, y NUNCA uno que diga
     * `reintentable: false` (aunque su HTTP esté en esta lista).
     */
    private const ESTADOS_REINTENTABLES = [429, 502, 503, 504];

    /** @var callable(float):void */
    private $dormir;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $maxReintentos,
        private readonly bool $verificarSsl,
        private readonly Transporte $transporte,
        ?callable $dormir = null
    ) {
        $this->dormir = $dormir ?? static function (float $segundos): void {
            usleep((int) round($segundos * 1_000_000));
        };
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $cuerpo
     * @param array<string, string>     $cabecerasExtra
     * @param bool $reintentable  si se puede repetir sin riesgo de duplicar (GET, calcular, emitir con Idempotency-Key, crear RUC)
     * @param bool $reintentar409 solo emitir: 409 = "emisión en curso con esa Idempotency-Key"
     * @param bool $binario       devolver los bytes del cuerpo en vez de decodificar JSON
     * @return array<mixed>|string
     */
    public function peticion(
        string $metodo,
        string $ruta,
        array $query = [],
        ?array $cuerpo = null,
        array $cabecerasExtra = [],
        bool $reintentable = true,
        bool $reintentar409 = false,
        bool $binario = false
    ): array|string {
        $url = $this->baseUrl . $ruta;
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $cabeceras = [
            'X-API-Key' => $this->apiKey,
            'Accept' => $binario ? '*/*' : 'application/json',
            'User-Agent' => 'factusmart-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ] + $cabecerasExtra;

        $cuerpoJson = null;
        if ($cuerpo !== null) {
            $cuerpoJson = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $cabeceras['Content-Type'] = 'application/json';
        }

        $intento = 0;
        while (true) {
            $esperaSugerida = null;
            try {
                $respuesta = $this->transporte->enviar($metodo, $url, $cabeceras, $cuerpoJson, $this->timeout, $this->verificarSsl);
            } catch (ErrorDeRed $e) {
                if ($reintentable && $intento < $this->maxReintentos) {
                    ($this->dormir)($this->espera($intento, null));
                    $intento++;
                    continue;
                }
                throw new FactuSmartError('No se pudo conectar con FactuSmart: ' . $e->getMessage(), 0, null, $e);
            }

            if ($respuesta->status >= 200 && $respuesta->status < 300) {
                if ($binario) {
                    return $respuesta->cuerpo;
                }
                $datos = $this->decodificar($respuesta->cuerpo);
                if (!is_array($datos)) {
                    throw new FactuSmartError('Respuesta del API no es JSON válido.', $respuesta->status, $respuesta->cuerpo);
                }
                return $datos;
            }

            $error = $this->crearError($respuesta);
            if ($error instanceof LimiteDePeticiones) {
                $esperaSugerida = $error->segundosDeEspera;
            }

            // `reintentable: false` explícito en el cuerpo MANDA: no se repite aunque el HTTP sea
            // 429/409/5xx (p.ej. 429 LIMITE_PRUEBAS_DIARIO). Sin la clave → regla por HTTP.
            $noReintentar = is_array($error->respuesta)
                && array_key_exists('reintentable', $error->respuesta)
                && $error->respuesta['reintentable'] === false;

            $puedeReintentar = $reintentable && !$noReintentar && (
                in_array($respuesta->status, self::ESTADOS_REINTENTABLES, true)
                || $error instanceof LimiteDePeticiones
                || ($reintentar409 && $respuesta->status === 409)
                // Señal del propio API (ERROR_INTERNO, SUNAT_NO_DISPONIBLE...). Solo cuenta si la
                // operación ya es reintentable: anular, emitir guía, reenviar, etc. nunca se repiten.
                || $error->reintentable
            );

            if ($puedeReintentar && $intento < $this->maxReintentos) {
                ($this->dormir)($this->espera($intento, $esperaSugerida));
                $intento++;
                continue;
            }

            throw $error;
        }
    }

    /** Espera creciente 1, 2, 4... s; si el API dijo cuánto esperar, se respeta. */
    private function espera(int $intento, ?int $sugerida): float
    {
        if ($sugerida !== null && $sugerida > 0) {
            return (float) $sugerida;
        }
        return (float) (2 ** $intento);
    }

    private function decodificar(string $cuerpo): mixed
    {
        if ($cuerpo === '') {
            return null;
        }
        try {
            return json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** Convierte una respuesta no-2xx en la excepción que corresponde. */
    public function crearError(Respuesta $respuesta): FactuSmartError
    {
        $status = $respuesta->status;
        $datos = $this->decodificar($respuesta->cuerpo);
        $cuerpo = is_array($datos) ? $datos : $respuesta->cuerpo;

        [$mensaje, $errores] = $this->extraerMensaje($datos, $status);

        // Servidores viejos convertían el 429 en 500 "Too Many Attempts.".
        $esLimite = $status === 429
            || (is_array($datos) && ($datos['codigo'] ?? null) === 'LIMITE_PETICIONES')
            || stripos($mensaje, 'Too Many Attempts') !== false;
        if ($esLimite) {
            // LIMITE_PRUEBAS_DIARIO (tope diario en RUCs demo) ya trae su propio mensaje claro.
            $esTopeDiario = is_array($datos) && ($datos['codigo'] ?? null) === 'LIMITE_PRUEBAS_DIARIO';
            return new LimiteDePeticiones(
                ($esTopeDiario ? '' : 'Límite de peticiones superado. ') . $mensaje,
                $status,
                $cuerpo,
                $this->segundosDeEspera($respuesta, $mensaje)
            );
        }

        return match ($status) {
            401 => new ErrorDeAutenticacion($mensaje, $status, $cuerpo),
            402 => new SinCreditos($mensaje, $status, $cuerpo),
            403 => new RucNoAutorizado($mensaje, $status, $cuerpo),
            404 => new NoEncontrado($mensaje, $status, $cuerpo),
            422 => new ErrorDeValidacion($mensaje, $status, $cuerpo, $errores),
            default => new FactuSmartError($mensaje, $status, $cuerpo),
        };
    }

    /**
     * @return array{0: string, 1: array<string, array<int, string>>}
     */
    private function extraerMensaje(mixed $datos, int $status): array
    {
        if (!is_array($datos)) {
            $genericos = [
                401 => 'API key inválida o ausente.',
                402 => 'Sin créditos disponibles.',
                403 => 'El RUC no está autorizado para esta cuenta.',
                404 => 'No encontrado.',
                422 => 'Datos inválidos.',
            ];
            return [($genericos[$status] ?? 'Error del API') . " (HTTP {$status})", []];
        }

        $mensaje = $datos['message'] ?? $datos['mensaje'] ?? null;
        if (is_string($mensaje) && $mensaje !== '') {
            // Desde la versión del API con `codigo`, el mapa de validación viene en `campos`.
            return [$mensaje, FactuSmartError::normalizarCampos($datos['campos'] ?? null)];
        }

        // Mapa de validación: o viene dentro de "message", o es el cuerpo entero.
        $mapa = is_array($mensaje) ? $mensaje : $datos;
        $errores = [];
        foreach ($mapa as $campo => $lista) {
            if (is_string($campo) && is_array($lista)) {
                $errores[$campo] = array_values(array_map('strval', array_filter($lista, 'is_scalar')));
            }
        }
        if ($errores !== []) {
            $partes = [];
            foreach ($errores as $campo => $lista) {
                $partes[] = $campo . ': ' . implode(' ', $lista);
            }
            return ['Datos inválidos. ' . implode(' | ', $partes), $errores];
        }

        return ["Error del API (HTTP {$status})", []];
    }

    private function segundosDeEspera(Respuesta $respuesta, string $mensaje): ?int
    {
        $retryAfter = $respuesta->cabecera('retry-after');
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            return (int) trim($retryAfter);
        }
        if (preg_match('/espera\s+(\d+)\s*segundo/i', $mensaje, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
