# FactuSmart SDK para PHP

SDK oficial del **API de facturación electrónica de FactuSmart** (SUNAT, Perú): facturas, boletas,
notas de crédito/débito, guías de remisión GRE 2.0 y anulaciones (facturas y boletas).

- PHP **8.1 o superior**, solo `ext-curl` y `ext-json`. **Cero dependencias.**
- Mismos nombres y comportamiento que los SDK de Node y Python.
- Los datos van y vuelven **tal cual los define el API** (claves en español), sin traducir.

Documentación del API: <https://factusmart.pe/api-facturacion-electronica/docs>

## Instalar

```bash
composer require factusmart/sdk
```

## Uso en 10 líneas

```php
$fs = new FactuSmart\Cliente(['apiKey' => 'TU_API_KEY', 'baseUrl' => 'https://TU_URL_BASE/api/v1/issuer', 'ruc' => '20123456789']);

['items' => $items, 'totales' => $totales] = FactuSmart\Cliente::calcularComprobante([
    ['descripcion' => 'Servicio de consultoría', 'cantidad' => 1, 'valor_unitario' => 100, 'afectacion' => '10'],
]);

$r = $fs->comprobantes->emitir([
    'codigo_tipo_documento' => '01',
    'datos_del_cliente_o_receptor' => ['codigo_tipo_documento_identidad' => '6', 'numero_documento' => '20555555555'],
    'items' => $items, 'totales' => $totales,
]);

echo $r['aceptado_por_sunat'] ? 'Aceptado ' : 'NO aceptado ', $r['serie_numero'];
```

> **`baseUrl` no es igual para todos.** Cada cuenta vive en un servidor distinto: copia la
> "URL base de la API" de tu panel de integrador (pestaña Configuración).

Hay un ejemplo completo en [`ejemplos/emitir_boleta.php`](ejemplos/emitir_boleta.php).

## La regla de oro: HTTP 200 no significa "aceptado por SUNAT"

Un `200` significa que FactuSmart **generó** tu comprobante. SUNAT puede estar caída o rechazarlo,
y entonces recibes `estado: "01"` con `aceptado_por_sunat: false` y el motivo en `sunat.descripcion`.
**El SDK no lanza excepción en ese caso**: es un resultado normal que tu sistema debe revisar.

```php
$r = $fs->comprobantes->emitir($datos);
if (!$r['aceptado_por_sunat']) {
    // $r['estado'], $r['sunat']['descripcion'], $r['accion_requerida']
    // Reintento gratis, sin gastar otro correlativo:
    $fs->comprobantes->reenviar($r['external_id']);
}
```

Revisa también `entorno` (`prueba` / `produccion`) y `tiene_validez_fiscal`: cada RUC nace en modo prueba.

## Constructor

| Opción | Obligatoria | Default | Nota |
|---|---|---|---|
| `apiKey` | sí | — | va en el header `X-API-Key` |
| `baseUrl` | sí | — | la URL de tu panel, ej. `https://s2.factusmart.pe/api/v1/issuer` |
| `ruc` | no | — | RUC emisor por defecto; se puede cambiar por llamada con `['ruc' => ...]` |
| `timeout` | no | `60` | segundos (SUNAT a veces tarda) |
| `maxReintentos` | no | `3` | ver "Reintentos" |
| `verificarSsl` | no | `true` | ponlo en `false` **solo** para pruebas locales con certificado propio |

Si falta `apiKey` o `baseUrl` se lanza `FactuSmartError` de inmediato.

## Métodos

Todos devuelven un `array` con la respuesta JSON del API, salvo `descargar()` que devuelve los **bytes**.
`$opciones` acepta `ruc` (sobreescribe el del constructor) y, en `emitir`, `idempotencyKey`.

| Método | Endpoint | Notas |
|---|---|---|
| `$fs->ping($opciones)` | `GET /ping` | prueba la API key; trae `creditos` |
| `$fs->cuenta->saldo()` | `GET /account` | `creditos_disponibles`, plan, RUCs |
| `$fs->series->listar($opciones)` | `GET /series` | FA01, BA01, FN01... |
| `$fs->comprobantes->emitir($datos, $opciones)` | `POST /documents` | 1 crédito; siempre con `Idempotency-Key` |
| `$fs->comprobantes->listar($filtros, $opciones)` | `GET /documents` | filtros: `estado`, `desde`, `hasta`, `limite`, `antes_de` |
| `$fs->comprobantes->consultar($externalId, $opciones)` | `GET /documents/{id}` | estado guardado + links |
| `$fs->comprobantes->descargar($externalId, $tipo, $opciones)` | `GET /documents/{id}/{tipo}` | `pdf`, `xml` o `cdr` → string binario |
| `$fs->comprobantes->reenviar($externalId, $opciones)` | `POST /documents/{id}/reenviar` | gratis; no se reintenta solo |
| `$fs->comprobantes->consultarSunat($externalId, $opciones)` | `POST /documents/{id}/consultar-sunat` | consulta en vivo; no se reintenta solo |
| `$fs->comprobantes->historial($externalId, $opciones)` | `GET /documents/{id}/historial` | línea de tiempo |
| `$fs->comprobantes->calcular($items, $opciones)` | `POST /calcular` | **nuevo**; el servidor arma `items` y `totales`; gratis, no emite; `$opciones`: `porcentaje_igv` (18 o 10.5) |
| `$fs->anulaciones->crear($datos, $opciones)` | `POST /anulaciones` | facturas y boletas; 1 crédito; **nunca** se reintenta solo |
| `$fs->anulaciones->estado($ticket, $opciones)` | `GET /anulaciones/{ticket}/status` | trae `tipo` y `documentos[]` |
| `$fs->guias->emitir($datos, $opciones)` | `POST /guias` | revisa `enviado`; **nunca** se reintenta solo |
| `$fs->guias->estado($externalId, $opciones)` | `GET /guias/{id}/status` | |
| `$fs->rucs->listar()` | `GET /rucs` | |
| `$fs->rucs->crear($datos)` | `POST /rucs` | idempotente por RUC (`ya_existia`) |
| `$fs->rucs->activar($ruc, $activo)` | `PATCH /rucs/{ruc}/activation` | `true` activa, `false` desactiva |
| `$fs->catalogos->listar($opciones)` | `GET /catalogos` | **nuevo**; catálogos SUNAT disponibles (`catalogos`) |
| `$fs->catalogos->consultar($nombre, $opciones)` | `GET /catalogos/{nombre}` | **nuevo**; `$nombre` = `unidades` o número SUNAT (`07`); `$opciones`: `q`, `ruc`. Usa solo filas con `activo: true` |

### Lo que `comprobantes->emitir()` completa por ti

Solo si **no** lo mandas tú:

- `ruc` → el del constructor (o el de `$opciones`). En los GET va como `?ruc=`; en POST/PATCH, en el cuerpo.
- `numero_documento` → `"#"` (FactuSmart asigna el correlativo). Si mandas tu propio número, se respeta.
- `fecha_de_emision` / `hora_de_emision` → fecha y hora **de Perú** (`America/Lima`), no la de tu servidor.
- `codigo_tipo_operacion` → `"0101"` (solo en facturas y boletas; en notas 07/08 no aplica).
- `codigo_tipo_moneda` → `"PEN"`.

`guias->emitir()` completa igual `ruc`, `numero_documento` y la fecha/hora de Perú.

## Calcular IGV y totales

### ¿Calcular en el servidor o local?

| | `$fs->comprobantes->calcular()` (servidor) | `Cliente::calcularComprobante()` (local) |
|---|---|---|
| Petición | sí (`POST /calcular`, gratis, no emite) | **ninguna** |
| Precio | `valor_unitario` (sin IGV) **o** `precio_unitario` (con IGV: 3 × S/ 10.00 = S/ 30.00) | solo `valor_unitario` sin IGV |
| Tasa IGV | 18 % o **10.5 %** (Ley 31556, `porcentaje_igv`) | solo 18 % |
| Lógica en tu lado | cero: el cálculo lo hace siempre FactuSmart | la del SDK (mismos números que el servidor en sus casos) |

Usa el del servidor si tienes precios con IGV, la tasa 10.5 % o quieres que el cálculo lo haga siempre
FactuSmart. Usa el local si quieres cero peticiones extra.

```php
$c = $fs->comprobantes->calcular([
    ['descripcion' => 'Menú', 'cantidad' => 3, 'precio_unitario' => 10, 'afectacion' => '10'],
], ['porcentaje_igv' => 10.5]);
// $c['items'] y $c['totales'] listos para emitir(). Errores en $e->campos con clave items.N.campo.
```

### Local, sin petición

```php
$r = FactuSmart\Cliente::calcularComprobante([
    ['codigo_interno' => 'P-001', 'descripcion' => 'Polo', 'cantidad' => 3, 'valor_unitario' => 10.555, 'afectacion' => '10'],
    ['descripcion' => 'Libro', 'cantidad' => 2, 'valor_unitario' => 5, 'afectacion' => '20', 'unidad_de_medida' => 'NIU'],
]);
// $r['items'] y $r['totales'] listos para emitir()
```

- `valor_unitario` es **sin IGV**.
- `afectacion`: `10` gravado (IGV 18 %), `20` exonerado, `30` inafecto, `40` exportación (IGV 0, suma en `total_exportacion`).
- Redondeo decimal exacto a 2 decimales por ítem (mitad hacia arriba, sin errores de coma flotante:
  `1.005` → `1.01`), igual que la función de la documentación pública.
- Si no mandas `codigo_interno`, la clave no aparece en el ítem (no se manda `null`).
- Da exactamente los mismos números que los SDK de Node y Python (ver `resultado_caso_comun.json`).
- Para otras afectaciones (gratuitas, IVAP, ISC, bolsas) arma los ítems tú mismo.

## Errores

Todas las excepciones heredan de `FactuSmart\Excepciones\FactuSmartError` y traen:

- `$e->status`: HTTP (`0` si fue de red).
- `$e->mensaje`: el `message` del API, en español, para mostrar.
- `$e->codigo`: código estable en MAYÚSCULAS para decidir en tu código (constantes en `FactuSmart\Codigos`).
  `null` si no hubo respuesta o el servidor aún no lo manda.
- `$e->reintentable`: `true` si el API dice que repetir la misma petición puede funcionar; `false` si no
  (o si el servidor no lo manda).
- `$e->campos`: en los 422, mapa campo → lista de mensajes. Vacío si no aplica.
- `$e->respuesta`: el cuerpo completo.

Decide por `$e->codigo`, nunca por el texto de `$e->mensaje` (puede cambiar).

| HTTP | Excepción | Cuándo |
|---|---|---|
| 401 | `ErrorDeAutenticacion` | API key ausente, inválida o cuenta desactivada |
| 402 | `SinCreditos` | sin saldo; no se emitió nada |
| 403 | `RucNoAutorizado` | el RUC no es de tu cuenta o está desactivado |
| 404 | `NoEncontrado` | comprobante, guía, ticket o RUC inexistente |
| 422 | `ErrorDeValidacion` | datos inválidos (el crédito no se cobra). `$e->errores` trae el mapa campo → mensajes cuando el API lo da |
| 429 | `LimiteDePeticiones` | límite superado; `$e->segundosDeEspera` si se pudo leer |
| otros / red | `FactuSmartError` | |

```php
use FactuSmart\Excepciones\{FactuSmartError, ErrorDeValidacion, SinCreditos};

try {
    $r = $fs->comprobantes->emitir($datos, ['idempotencyKey' => $venta->id]);
} catch (ErrorDeValidacion $e) {
    echo "Corrige los datos: {$e->mensaje}";
} catch (SinCreditos $e) {
    echo 'Recarga créditos';
} catch (FactuSmartError $e) {
    echo "Error {$e->status} {$e->codigo}: {$e->mensaje}";
}
```

Códigos más comunes:

| `codigo` | Qué pasó |
|---|---|
| `NO_AUTENTICADO` | API key inválida o ausente |
| `PLAN_VENCIDO` / `SIN_CREDITOS` | renueva el plan / recarga créditos |
| `RUC_NO_AUTORIZADO` / `RUC_DESACTIVADO` / `RUC_REQUERIDO` | problema con el RUC emisor |
| `DATOS_INVALIDOS` | revisa `$e->campos` |
| `NO_ENCONTRADO` | el comprobante, guía, ticket o RUC no existe |
| `EMISION_EN_CURSO` | otra petición con la misma `Idempotency-Key` sigue en curso |
| `SUNAT_NO_DISPONIBLE` | SUNAT no responde; reintentable |
| `LIMITE_PETICIONES` | espera `$e->segundosDeEspera` |
| `LIMITE_PRUEBAS_DIARIO` | RUC demo: llegaste al tope de 200 operaciones/día de la cuenta (429, **no reintentable**; se reinicia a medianoche de Lima) |
| `ANULACION_MIXTA` | mezclaste facturas y boletas en una anulación (422) |
| `LLAVE_DE_PRUEBA_EN_PRODUCCION` | usaste una llave de prueba contra un RUC en producción (403) |
| `ERROR_INTERNO` | error del servidor; reintentable |

Lista completa en `FactuSmart\Codigos` (también `SIN_PERMISO`, `RUTA_NO_ENCONTRADA`, `METODO_NO_PERMITIDO`,
`CONFLICTO`, `RUC_SIN_TENANT`, `CERTIFICADO_NO_CONFIGURADO`, `ERROR_EMISION`, `RECHAZADO_SUNAT`,
`REENVIO_FALLIDO`, y `ERROR_<status>` cuando no hay uno específico).

## Verificar webhooks

FactuSmart firma cada webhook con el header `X-FactuSmart-Firma` (HMAC-SHA256 del cuerpo con tu secreto
de webhooks). Verifícalo antes de confiar en el contenido:

```php
$cuerpo = file_get_contents('php://input');              // el cuerpo CRUDO, tal cual llegó
$firma  = $_SERVER['HTTP_X_FACTUSMART_FIRMA'] ?? '';

if (!FactuSmart\Cliente::verificarFirmaWebhook($cuerpo, $firma, getenv('FACTUSMART_WEBHOOK_SECRETO'))) {
    http_response_code(401);
    exit;
}
$evento = json_decode($cuerpo, true);
// ... procesa $evento ...
http_response_code(200);
```

> **Usa el cuerpo crudo.** Si haces `json_decode` y luego `json_encode`, los bytes cambian (espacios,
> escapes, orden) y la firma ya no coincide. En frameworks: `$request->getContent()` (Laravel/Symfony).

Nunca lanza excepción: firma o secreto vacíos, o una firma que no sea de 64 caracteres hex, devuelven `false`.

## Anulaciones (facturas y boletas)

`$fs->anulaciones->crear()` anula **facturas** (comunicación de baja) y **boletas** (resumen diario; solo
boletas aceptadas, estado `05`). El método es el mismo: el API elige el camino y lo dice en la respuesta.

```php
$r = $fs->anulaciones->crear([
    'fecha_de_emision_de_documentos' => '14-10-2026',   // DD-MM-AAAA, la de emisión de los comprobantes
    'documentos' => [['external_id' => $externalId, 'motivo_anulacion' => 'Error en el comprobante']],
]);
// $r['tipo']          'baja' (facturas) | 'resumen' (boletas)
// $r['identificador'] 'RA-20261014-1'   | 'RC-20261014-1'
// $r['ticket']        para $fs->anulaciones->estado($r['ticket'])
```

- No mezcles facturas y boletas en la misma petición: lanza `ErrorDeValidacion` con `codigo`
  `ANULACION_MIXTA`. Haz una petición por tipo.
- La respuesta trae también `external_id`, `creditos_restantes`, `credito_cobrado` y, en RUCs demo,
  `modo_demo` y `pruebas_restantes_hoy`.
- Nunca se reintenta sola (no admite `Idempotency-Key`).


## Reintentos e idempotencia

- `comprobantes->emitir()` **siempre** manda el header `Idempotency-Key`: el tuyo
  (`['idempotencyKey' => ...]`, recomendado: el id de la venta en tu sistema) o un UUID v4 generado.
  **La misma clave se usa en todos los reintentos de esa llamada**, así un reintento nunca emite dos veces.
  Si vuelves a llamar con la misma clave, el API devuelve el mismo comprobante sin cobrar otra vez.
- Se reintenta solo ante error de red/timeout, HTTP 429, 502, 503 y 504,
  esperando 1 s, 2 s, 4 s… (o lo que diga `Retry-After` / "Espera N segundos"). En `emitir`, también el
  `409` ("emisión en curso con esa Idempotency-Key"). Y cualquier error cuyo cuerpo diga
  `reintentable: true` (p.ej. `ERROR_INTERNO`, `SUNAT_NO_DISPONIBLE`), solo en las operaciones de abajo.
- **`reintentable: false` manda:** si el cuerpo del error lo dice, no se reintenta nunca, aunque sea 429,
  409 o 5xx. Caso real: `429` con `codigo` `LIMITE_PRUEBAS_DIARIO` (tope diario de los RUCs demo) lanza
  `LimiteDePeticiones` tras **una sola** petición, con `$e->codigo === Codigos::LIMITE_PRUEBAS_DIARIO` y
  `$e->reintentable === false`. Decide por `$e->codigo`, no por la clase. Si el servidor no manda
  `reintentable`, se usa la regla por HTTP.
- Se reintentan: todas las consultas (GET), `comprobantes->calcular()` (no tiene efectos),
  `comprobantes->emitir()` y `rucs->crear()` (idempotente por RUC).
- **Nunca** se reintentan solos: `anulaciones->crear()`, `guias->emitir()`, `comprobantes->reenviar()`,
  `comprobantes->consultarSunat()`, `rucs->activar()`.
- `maxReintentos => 0` desactiva los reintentos.

## RUCs demo (modo prueba)

En un RUC demo **no se cobra crédito**: las respuestas traen `credito_cobrado: false`, `modo_demo: true` y
`pruebas_restantes_hoy`. El saldo no baja. A cambio hay un tope de **200 operaciones/día por cuenta**
(`429 LIMITE_PRUEBAS_DIARIO`, no reintentable). Cada RUC nace en modo prueba (SUNAT beta, sin validez fiscal).

## Pruebas

```bash
composer install
vendor/bin/phpunit --testsuite unidad        # sin red
vendor/bin/phpunit --testsuite integracion   # contra un API LOCAL (emite 3 boletas demo)
php scripts/caso_comun.php                   # regenera resultado_caso_comun.json
```

Las pruebas de integración leen `FACTUSMART_BASE_URL`, `FACTUSMART_API_KEY`, `FACTUSMART_RUC` y
`FACTUSMART_VERIFICAR_SSL` (valores por defecto en `phpunit.xml`) y se niegan a correr contra un host
que no sea local. La prueba de contrato lee el `openapi.yaml` oficial (`FACTUSMART_OPENAPI`, o la copia en
`tests/fixtures/`) y falla si algún `operationId` no tiene método en el SDK.

## Licencia

MIT. Ver [LICENSE](LICENSE).
