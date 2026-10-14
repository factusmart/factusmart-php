<?php

declare(strict_types=1);

namespace FactuSmart\Http;

/** Transporte por defecto, con ext-curl. */
final class TransporteCurl implements Transporte
{
    public function enviar(
        string $metodo,
        string $url,
        array $cabeceras,
        ?string $cuerpo,
        int $timeout,
        bool $verificarSsl
    ): Respuesta {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ErrorDeRed('No se pudo iniciar cURL.');
        }

        $lineas = [];
        foreach ($cabeceras as $nombre => $valor) {
            $lineas[] = $nombre . ': ' . $valor;
        }

        $recibidas = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lineas,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verificarSsl,
            CURLOPT_SSL_VERIFYHOST => $verificarSsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$recibidas): int {
                $partes = explode(':', $linea, 2);
                if (count($partes) === 2) {
                    $recibidas[strtolower(trim($partes[0]))] = trim($partes[1]);
                }
                return strlen($linea);
            },
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }

        $salida = curl_exec($ch);
        if ($salida === false) {
            $error = curl_error($ch);
            $codigo = curl_errno($ch);
            curl_close($ch);
            throw new ErrorDeRed("Error de red ({$codigo}): {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Respuesta($status, $recibidas, (string) $salida);
    }
}
