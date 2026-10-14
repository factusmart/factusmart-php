<?php

declare(strict_types=1);

namespace FactuSmart;

use FactuSmart\Excepciones\FactuSmartError;
use FactuSmart\Http\ClienteHttp;
use FactuSmart\Http\Transporte;
use FactuSmart\Http\TransporteCurl;
use FactuSmart\Recursos\Anulaciones;
use FactuSmart\Recursos\Comprobantes;
use FactuSmart\Recursos\Cuenta;
use FactuSmart\Recursos\Guias;
use FactuSmart\Recursos\Rucs;
use FactuSmart\Recursos\Series;

/**
 * Cliente del API de facturación electrónica de FactuSmart.
 *
 *   $fs = new FactuSmart\Cliente([
 *       'apiKey'  => 'TU_API_KEY',
 *       'baseUrl' => 'https://TU_URL_BASE/api/v1/issuer',
 *       'ruc'     => '20123456789',
 *   ]);
 *   $r = $fs->comprobantes->emitir([...]);
 */
final class Cliente
{
    public const VERSION = ClienteHttp::VERSION;

    /**
     * operationId del openapi.yaml => [recurso, método]. Recurso null = método del propio Cliente.
     * La prueba de contrato falla si el API publica un operationId que no está aquí.
     */
    public const OPERACIONES = [
        'ping' => [null, 'ping'],
        'getAccount' => ['cuenta', 'saldo'],
        'listSeries' => ['series', 'listar'],
        'emitDocument' => ['comprobantes', 'emitir'],
        'listDocuments' => ['comprobantes', 'listar'],
        'getDocument' => ['comprobantes', 'consultar'],
        'downloadDocumentFile' => ['comprobantes', 'descargar'],
        'resendDocument' => ['comprobantes', 'reenviar'],
        'querySunatStatus' => ['comprobantes', 'consultarSunat'],
        'getDocumentHistory' => ['comprobantes', 'historial'],
        'voidDocument' => ['anulaciones', 'crear'],
        'getVoidStatus' => ['anulaciones', 'estado'],
        'emitDispatchGuide' => ['guias', 'emitir'],
        'getDispatchGuideStatus' => ['guias', 'estado'],
        'listRucs' => ['rucs', 'listar'],
        'createRuc' => ['rucs', 'crear'],
        'setRucActivation' => ['rucs', 'activar'],
        // Webhook (lo envía el API a tu servidor): el SDK aporta la verificación de la firma.
        'webhookComprobanteEstado' => [null, 'verificarFirmaWebhook'],
    ];

    /** Afectaciones que entiende calcularComprobante(). */
    private const AFECTACIONES = ['10', '20', '30', '40'];
    private const TASA_IGV = 0.18;

    public readonly Cuenta $cuenta;
    public readonly Series $series;
    public readonly Comprobantes $comprobantes;
    public readonly Anulaciones $anulaciones;
    public readonly Guias $guias;
    public readonly Rucs $rucs;

    private readonly ClienteHttp $http;
    private readonly ?string $ruc;

    /**
     * @param array{
     *   apiKey?: string, baseUrl?: string, ruc?: string|null, timeout?: int,
     *   maxReintentos?: int, verificarSsl?: bool, transporte?: Transporte, dormir?: callable
     * } $opciones
     *
     * `transporte` y `dormir` existen para pruebas (inyectar red falsa / no esperar de verdad).
     */
    public function __construct(array $opciones)
    {
        $apiKey = trim((string) ($opciones['apiKey'] ?? $opciones['api_key'] ?? ''));
        $baseUrl = trim((string) ($opciones['baseUrl'] ?? $opciones['base_url'] ?? ''));
        if ($apiKey === '') {
            throw new FactuSmartError('Falta "apiKey": la encuentras en tu panel de integrador, pestaña Configuración.');
        }
        if ($baseUrl === '') {
            throw new FactuSmartError('Falta "baseUrl": cada cuenta vive en un servidor distinto; copia la "URL base de la API" de tu panel (ej. https://s2.factusmart.pe/api/v1/issuer).');
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new FactuSmartError('"baseUrl" debe empezar con https:// (ej. https://s2.factusmart.pe/api/v1/issuer).');
        }

        $timeout = (int) ($opciones['timeout'] ?? 60);
        $maxReintentos = (int) ($opciones['maxReintentos'] ?? $opciones['max_reintentos'] ?? 3);
        $verificarSsl = (bool) ($opciones['verificarSsl'] ?? $opciones['verificar_ssl'] ?? true);
        $ruc = $opciones['ruc'] ?? null;
        $this->ruc = $ruc === null || $ruc === '' ? null : (string) $ruc;

        $this->http = new ClienteHttp(
            $apiKey,
            rtrim($baseUrl, '/'),
            max(1, $timeout),
            max(0, $maxReintentos),
            $verificarSsl,
            $opciones['transporte'] ?? new TransporteCurl(),
            $opciones['dormir'] ?? null
        );

        $this->cuenta = new Cuenta($this->http, $this->ruc);
        $this->series = new Series($this->http, $this->ruc);
        $this->comprobantes = new Comprobantes($this->http, $this->ruc);
        $this->anulaciones = new Anulaciones($this->http, $this->ruc);
        $this->guias = new Guias($this->http, $this->ruc);
        $this->rucs = new Rucs($this->http, null);
    }

    /**
     * GET /ping — confirma que la API key funciona y devuelve el saldo.
     * @param array<string, mixed> $opciones ruc
     * @return array<string, mixed>
     */
    public function ping(array $opciones = []): array
    {
        $ruc = $opciones['ruc'] ?? $this->ruc;
        /** @var array<string, mixed> */
        return $this->http->peticion('GET', '/ping', ['ruc' => $ruc]);
    }

    /**
     * Verifica que un webhook lo mandó FactuSmart.
     *
     * FactuSmart firma cada webhook con el header `X-FactuSmart-Firma` =
     * HMAC-SHA256 del cuerpo CRUDO con tu secreto de webhooks, en hex (64 caracteres, sin prefijo).
     *
     *   $cuerpo = file_get_contents('php://input');   // crudo: NO json_decode + json_encode
     *   $firma  = $_SERVER['HTTP_X_FACTUSMART_FIRMA'] ?? '';
     *   if (!FactuSmart\Cliente::verificarFirmaWebhook($cuerpo, $firma, $secreto)) { http_response_code(401); exit; }
     *
     * Nunca lanza excepción: firma o secreto vacíos, o firma que no sea 64 hex, devuelven false.
     * Compara en tiempo constante (hash_equals).
     */
    public static function verificarFirmaWebhook(string $cuerpoCrudo, string $firma, string $secreto): bool
    {
        $firma = strtolower(trim($firma));
        if ($secreto === '' || !preg_match('/^[0-9a-f]{64}$/D', $firma)) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $cuerpoCrudo, $secreto), $firma);
    }

    /**
     * Calcula IGV y totales. Recibe ítems con:
     *   descripcion, cantidad, valor_unitario (SIN IGV), afectacion ('10' gravado 18%,
     *   '20' exonerado, '30' inafecto, '40' exportación) y opcional codigo_interno, unidad_de_medida (NIU).
     *
     * Devuelve ['items' => [...], 'totales' => [...]] listos para comprobantes->emitir().
     * Redondeo a 2 decimales por ítem (mitad hacia arriba), igual que la doc pública.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{items: array<int, array<string, mixed>>, totales: array<string, float>}
     */
    public static function calcularComprobante(array $items): array
    {
        if ($items === []) {
            throw new FactuSmartError('calcularComprobante: la lista de ítems está vacía.');
        }

        $gravadas = 0.0;
        $exoneradas = 0.0;
        $inafectas = 0.0;
        $exportacion = 0.0;
        $totalIgv = 0.0;
        $salida = [];

        foreach (array_values($items) as $i => $it) {
            $n = $i + 1;
            foreach (['descripcion', 'cantidad', 'valor_unitario', 'afectacion'] as $campo) {
                if (!isset($it[$campo]) || $it[$campo] === '') {
                    throw new FactuSmartError("calcularComprobante: al ítem {$n} le falta \"{$campo}\".");
                }
            }
            if (!is_numeric($it['cantidad']) || !is_numeric($it['valor_unitario'])) {
                throw new FactuSmartError("calcularComprobante: en el ítem {$n}, cantidad y valor_unitario deben ser números.");
            }
            $afectacion = (string) $it['afectacion'];
            if (!in_array($afectacion, self::AFECTACIONES, true)) {
                throw new FactuSmartError("calcularComprobante: afectación \"{$afectacion}\" no soportada en el ítem {$n} (usa 10, 20, 30 o 40).");
            }

            $cantidad = $it['cantidad'] + 0;
            $valorUnitario = (float) $it['valor_unitario'];
            $gravado = $afectacion === '10';

            $base = self::r2($valorUnitario * $cantidad);
            $igv = $gravado ? self::r2($base * self::TASA_IGV) : 0.0;

            switch ($afectacion) {
                case '10': $gravadas += $base; break;
                case '20': $exoneradas += $base; break;
                case '30': $inafectas += $base; break;
                case '40': $exportacion += $base; break;
            }
            $totalIgv += $igv;

            // Sin codigo_interno se omite la clave (no se manda null), igual que en Node y Python.
            $item = [];
            if (isset($it['codigo_interno']) && $it['codigo_interno'] !== '') {
                $item['codigo_interno'] = (string) $it['codigo_interno'];
            }
            $salida[] = $item + [
                'descripcion' => (string) $it['descripcion'],
                'unidad_de_medida' => (string) ($it['unidad_de_medida'] ?? 'NIU'),
                'cantidad' => $cantidad,
                'valor_unitario' => self::r2($valorUnitario),
                'codigo_tipo_precio' => '01',
                'precio_unitario' => self::r2($valorUnitario * ($gravado ? 1 + self::TASA_IGV : 1)),
                'codigo_tipo_afectacion_igv' => $afectacion,
                'total_base_igv' => $base,
                'porcentaje_igv' => $gravado ? 18 : 0,
                'total_igv' => $igv,
                'total_impuestos' => $igv,
                'total_valor_item' => $base,
                'total_item' => self::r2($base + $igv),
            ];
        }

        $totalValor = self::r2($gravadas + $exoneradas + $inafectas + $exportacion);
        $totalIgv = self::r2($totalIgv);

        return [
            'items' => $salida,
            'totales' => [
                'total_operaciones_gravadas' => self::r2($gravadas),
                'total_operaciones_exoneradas' => self::r2($exoneradas),
                'total_operaciones_inafectas' => self::r2($inafectas),
                'total_exportacion' => self::r2($exportacion),
                'total_igv' => $totalIgv,
                'total_impuestos' => $totalIgv,
                'total_valor' => $totalValor,
                'total_venta' => self::r2($totalValor + $totalIgv),
            ],
        ];
    }

    /**
     * Redondeo decimal exacto a 2 decimales, mitad hacia arriba (lejos de cero).
     * Trabaja sobre la representación decimal más corta del número (la misma que
     * imprime json_encode, Python repr o JS String), no sobre el binario:
     * 1.005 → "1.005" → 1.01, aunque en binario sea 1.00499999...
     */
    private static function r2(float $n): float
    {
        $texto = json_encode($n, JSON_THROW_ON_ERROR); // serialize_precision -1: decimal más corto
        if (stripos($texto, 'e') !== false) {
            $texto = rtrim(rtrim(sprintf('%.20F', $n), '0'), '.');
        }
        $negativo = str_starts_with($texto, '-');
        $texto = ltrim($texto, '-');
        [$entero, $decimales] = array_pad(explode('.', $texto, 2), 2, '');
        $decimales = str_pad($decimales, 3, '0');

        $centavos = (int) ($entero . substr($decimales, 0, 2));
        if ((int) $decimales[2] >= 5) {
            $centavos++;
        }
        $r = $centavos / 100;
        if ($r == 0.0) {
            return 0.0; // evita -0.0
        }
        return $negativo ? -$r : $r;
    }
}
