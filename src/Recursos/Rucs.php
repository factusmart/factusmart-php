<?php

declare(strict_types=1);

namespace FactuSmart\Recursos;

use FactuSmart\Excepciones\FactuSmartError;

/** RUCs de la cuenta (marca blanca / multi-empresa). Estos endpoints nunca usan el RUC por defecto. */
final class Rucs extends Recurso
{
    /**
     * GET /rucs
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/rucs');
    }

    /**
     * POST /rucs — da de alta un RUC (nace en modo prueba). Es idempotente por RUC
     * en el API (si ya es tuyo devuelve ya_existia=true), por eso sí se reintenta.
     *
     * @param array<string, mixed> $datos ruc, empresa, correo, clave
     * @return array<string, mixed>
     */
    public function crear(array $datos): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('POST', '/rucs', [], $datos, reintentable: true);
    }

    /**
     * PATCH /rucs/{ruc}/activation — activa (true) o desactiva (false). No se reintenta solo.
     * @return array<string, mixed>
     */
    public function activar(string $ruc, bool $activo): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('PATCH', '/rucs/' . self::segmento($ruc, 'ruc') . '/activation', [], ['activo' => $activo], reintentable: false);
    }

    // ───────────── Configuración del RUC ─────────────
    // El RUC va SOLO en la ruta (el API ignora X-RUC y `ruc` del cuerpo): no se usa el RUC por defecto.

    /** Tamaño máximo del certificado que acepta el API. */
    public const CERTIFICADO_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * PUT /rucs/{ruc}/certificado — instala el certificado digital (.pfx / .p12) del RUC.
     * El API comprueba que abre con la clave, que está vigente y que es de este RUC. No se reintenta solo.
     *
     * `$certificado` puede ser:
     * - la RUTA a un archivo .pfx/.p12 (string sin bytes nulos que apunta a un archivo existente), o
     * - los BYTES crudos del .pfx (p. ej. `file_get_contents(...)` o lo que te subió tu usuario).
     *
     * Regla para distinguirlos: si el string contiene un byte nulo, no es UTF-8 válido o empieza
     * con 0x30 (inicio de todo .pfx en DER), se toma como bytes. Si no, se toma como ruta y debe
     * existir; si no existe → FactuSmartError sin petición. El SDK hace el base64: no lo codifiques tú.
     *
     * La clave y el contenido del certificado nunca aparecen en los mensajes de error del SDK.
     *
     * @return array<string, mixed> success, ruc, certificado{instalado, titular, vence, dias_para_vencer, ruc_coincide}, aviso?
     */
    public function subirCertificado(
        string $ruc,
        #[\SensitiveParameter] string $certificado,
        #[\SensitiveParameter] string $clave
    ): array {
        $ruta = self::rutaRuc($ruc, '/certificado');
        $bytes = self::bytesDelCertificado($certificado);
        if ($clave === '') {
            throw new FactuSmartError('Falta la clave del certificado.');
        }
        $base64 = base64_encode($bytes);

        /** @var array<string, mixed> */
        return $this->http->peticion(
            'PUT',
            $ruta,
            [],
            ['certificado' => $base64, 'clave' => $clave],
            reintentable: false,
            secretos: [$clave, $base64]
        );
    }

    /**
     * PUT /rucs/{ruc}/sunat — guarda el usuario secundario SOL y su clave, y/o cambia el entorno.
     * No llama a SUNAT (para eso: verificar()). No se reintenta solo.
     *
     * @param array{usuario_sol?: string, clave_sol?: string, entorno?: string} $datos
     *        `usuario_sol` y `clave_sol` van juntos; `entorno`: `prueba` | `produccion`.
     * @return array<string, mixed> la configuración resultante (igual que configuracion()), sin claves
     */
    public function configurarSunat(string $ruc, #[\SensitiveParameter] array $datos): array
    {
        $ruta = self::rutaRuc($ruc, '/sunat');
        $cuerpo = array_intersect_key($datos, ['usuario_sol' => 1, 'clave_sol' => 1, 'entorno' => 1]);
        if ($cuerpo === []) {
            throw new FactuSmartError('configurarSunat(): manda usuario_sol + clave_sol, o entorno, o los tres.');
        }
        if (isset($cuerpo['entorno']) && !in_array($cuerpo['entorno'], ['prueba', 'produccion', 'demo'], true)) {
            throw new FactuSmartError('configurarSunat(): entorno debe ser "prueba" o "produccion".');
        }
        $secretos = isset($cuerpo['clave_sol']) && is_string($cuerpo['clave_sol']) ? [$cuerpo['clave_sol']] : [];

        /** @var array<string, mixed> */
        return $this->http->peticion('PUT', $ruta, [], $cuerpo, reintentable: false, secretos: $secretos);
    }

    /**
     * POST /rucs/{ruc}/verificar — prueba real del certificado y del usuario SOL contra SUNAT, sin emitir.
     * No tiene efectos, por eso se reintenta como un GET.
     *
     * @return array<string, mixed> success, ruc, listo_para_emitir, entorno, checks{certificado{ok, mensaje}, sol{ok, mensaje}}
     */
    public function verificar(string $ruc): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('POST', self::rutaRuc($ruc, '/verificar'), reintentable: true);
    }

    /**
     * GET /rucs/{ruc}/configuracion — entorno, certificado, SOL (enmascarado), envío y qué falta para producción.
     *
     * @return array<string, mixed>
     */
    public function configuracion(string $ruc): array
    {
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', self::rutaRuc($ruc, '/configuracion'));
    }

    private static function rutaRuc(string $ruc, string $sufijo): string
    {
        $ruc = trim($ruc);
        if (!preg_match('/^\d{11}$/', $ruc)) {
            throw new FactuSmartError('El RUC debe tener 11 dígitos.');
        }
        return '/rucs/' . $ruc . $sufijo;
    }

    private static function bytesDelCertificado(#[\SensitiveParameter] string $certificado): string
    {
        if ($certificado === '') {
            throw new FactuSmartError('Falta el certificado (ruta del .pfx/.p12 o su contenido).');
        }
        $pareceBinario = str_contains($certificado, "\0")
            || preg_match('//u', $certificado) !== 1
            || $certificado[0] === "\x30";
        if ($pareceBinario) {
            $bytes = $certificado;
        } else {
            if (strlen($certificado) > 4096 || !@is_file($certificado)) {
                throw new FactuSmartError(
                    'No se encontró el archivo del certificado'
                    . (strlen($certificado) <= 260 ? ': ' . $certificado : '')
                    . '. Pasa la ruta de un .pfx/.p12 existente o sus bytes.'
                );
            }
            $bytes = @file_get_contents($certificado);
            if ($bytes === false) {
                throw new FactuSmartError('No se pudo leer el archivo del certificado: ' . $certificado);
            }
            if ($bytes === '') {
                throw new FactuSmartError('El archivo del certificado está vacío: ' . $certificado);
            }
        }
        if (strlen($bytes) > self::CERTIFICADO_MAX_BYTES) {
            throw new FactuSmartError('El certificado pesa más de 5 MB; el API no lo acepta.');
        }
        return $bytes;
    }
}
