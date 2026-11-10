# Cambios

## 0.6.0 — 2026-11-10

- `rucs->configurarSunat()` acepta `confirmar` (bool, opcional; solo se envía si lo pasas): obligatorio en
  `true` para pasar a `entorno: prueba` un RUC que ya emite en producción.
- Códigos nuevos en `FactuSmart\Codigos`: `LIMITE_VERIFICACIONES` (429, máx. 10 verificaciones por hora por
  llave y RUC), `CONFIRMAR_CAMBIO_ENTORNO` (422, no reintentable) y `PSE_NO_DISPONIBLE` (503, reintentable).
  `RUC_DESACTIVADO` (403) aplica ahora también a certificado, sunat y verificar.
- `429 LIMITE_VERIFICACIONES` lanza `LimiteDePeticiones` con `segundosDeEspera` (de `Retry-After`) y **no** se
  reintenta solo: la espera puede ser de varios minutos.
- Docblocks y README: los dos caminos para producción (`propio`: certificado + SOL; `pse`: credenciales Smart
  PSE + SOL, sin certificado), la designación de Smart PSE en SUNAT (que `verificar()` no comprueba), el
  faltante `certificado_otro_ruc`, `sol` también con `pse` en producción y `aviso` en `configuracion()`.
- Contrato: `tests/fixtures/openapi.yaml` actualizado; nueva prueba que exige una constante por cada valor del
  enum `CodigoError`.
- `Cliente::VERSION` y el User-Agent: `0.6.0`.

## 0.5.0 — 2026-11-10

- `rucs->configurarSunat($ruc, $datos)` acepta la **modalidad de firma** (`PUT /rucs/{ruc}/sunat`):
  `modalidad` (`propio` | `pse`) y, con Smart PSE, `pse_usuario` + `pse_clave` (credenciales CPE de la
  empresa en Smart PSE). Pares incompletos (`pse_usuario` sin `pse_clave`, o `usuario_sol` sin
  `clave_sol`) fallan localmente, sin petición.
- `pse_clave` se reemplaza por `***` en el mensaje, la respuesta y los campos de cualquier error,
  igual que `clave_sol`. El PUT sigue sin reintentarse.
- La respuesta documenta `modalidad`, `pse{proveedor, configurado, usuario}` y `aviso`; `verificar()`
  trae `modalidad` y `checks.pse` con Smart PSE.

## 0.4.0 — 2026-11-10

- Configuración del RUC por API (tag «Configuración del RUC»), en el recurso `rucs`:
  - `subirCertificado($ruc, $certificado, $clave)` → `PUT /rucs/{ruc}/certificado` (`uploadRucCertificate`).
    `$certificado` es la ruta de un `.pfx`/`.p12` o sus bytes; el SDK lo lee y lo codifica en base64.
    Bytes = trae byte nulo, no es UTF-8 o empieza con `0x30`; si no, es ruta y debe existir (si no, error sin
    petición). Máx. 5 MB. No se reintenta solo.
  - `configurarSunat($ruc, $datos)` → `PUT /rucs/{ruc}/sunat` (`updateRucSunat`): `usuario_sol`, `clave_sol`,
    `entorno` (`prueba` | `produccion`). Solo manda esas claves. No se reintenta solo.
  - `verificar($ruc)` → `POST /rucs/{ruc}/verificar` (`verifyRucConfig`), sin cuerpo; se reintenta como un GET.
  - `configuracion($ruc)` → `GET /rucs/{ruc}/configuracion` (`getRucConfig`).
  - El RUC va solo en la ruta (se valida que tenga 11 dígitos); nunca `X-RUC`, `?ruc=` ni el RUC por defecto.
- Seguridad: las claves y el certificado enviados se tapan con `***` en el cuerpo de cualquier respuesta de
  error antes de crear la excepción (`getMessage()`, `$e->mensaje`, `$e->respuesta`, `$e->campos`, `$e->errores`),
  también si vienen escapados en JSON; un error de red en esas llamadas no adjunta la excepción previa.
  Cuerpo, cabeceras (API key) y claves van marcados `#[\SensitiveParameter]`: en PHP ≥ 8.2 no salen en las trazas.
- Contrato: `tests/fixtures/openapi.yaml` actualizado a la spec vigente.
- `Cliente::VERSION` y el User-Agent: `0.4.0`.

## 0.3.0 — 2026-11-06

- Nuevo recurso `consultas` (`GET /consultas/{tipo}/{numero}`, operationId `lookupDocument`):
  `consultarRuc($numero)`, `consultarDni($numero)` y `consultar($tipo, $numero)`. Devuelve `nombre`,
  `direccion`, `estado`, `condicion`, `ubigeo` (null si la fuente no los trae), `desde_cache`,
  `consultas_restantes`, `periodo` (`mes` | `dia`) y `se_renueva`. No gasta créditos y **nunca** manda `ruc`
  (aunque el cliente tenga uno por defecto). Un `tipo` distinto de `ruc`/`dni` lanza `ErrorDeValidacion`
  local (422, `DATOS_INVALIDOS`, `campos['tipo']`) sin petición. Se reintenta como un GET.
- `429 LIMITE_CONSULTAS` (cupo de consultas agotado) lanza `LimiteDePeticiones` tras **una sola** petición
  (`reintentable: false` manda, como ya pasaba con `LIMITE_PRUEBAS_DIARIO`), con el mensaje del API sin
  prefijo y `se_renueva` en `$e->respuesta`. `503 CONSULTA_NO_DISPONIBLE` y `429 LIMITE_PETICIONES` sí se reintentan.
- `FactuSmart\Codigos`: nuevas constantes `LIMITE_CONSULTAS` y `CONSULTA_NO_DISPONIBLE`.
- Nuevo `$fs->raiz()` (`GET /`, operationId `apiRoot`): confirma que el API responde (`estado: ok`).
- Contrato: `tests/fixtures/openapi.yaml` actualizado a la spec vigente.
- `Cliente::VERSION` y el User-Agent: `0.3.0`.


## 0.2.0 — 2026-10-14

- Toda excepción trae `$e->codigo` (p.ej. `SIN_CREDITOS`, `NO_ENCONTRADO`), `$e->reintentable` y
  `$e->campos` (mapa campo → mensajes en los 422). Constantes en `FactuSmart\Codigos`.
  Con un servidor que aún no manda estos datos: `codigo` null, `reintentable` false, `campos` vacío.
- Reintentos: las operaciones que ya se reintentaban (GET, `comprobantes->emitir()` con su misma
  `Idempotency-Key`, `rucs->crear()`) ahora también se reintentan si el API responde
  `reintentable: true` (p.ej. `ERROR_INTERNO`, `SUNAT_NO_DISPONIBLE`). Las operaciones que nunca se
  reintentan solas siguen igual.
- Nuevo `FactuSmart\Cliente::verificarFirmaWebhook($cuerpoCrudo, $firma, $secreto)` para validar el
  header `X-FactuSmart-Firma` de los webhooks.
- `comprobantes->descargar()` de un comprobante inexistente: el API ahora responde 404 en JSON; el SDK
  lanza `NoEncontrado` con el `message` y el `codigo` del API.
- `ErrorDeValidacion::$errores` se llena también desde `campos`.
- El User-Agent y `Cliente::VERSION` ahora dicen la versión real (`0.2.0`; antes decían `1.0.0`).
- Nuevo `comprobantes->calcular($items, ['porcentaje_igv' => ...])` (`POST /calcular`): el servidor arma
  `items` y `totales`; admite `precio_unitario` con IGV y la tasa 10.5 % (Ley 31556). No manda `ruc`.
  Se reintenta como un GET. `Cliente::calcularComprobante()` (local, sin red) se mantiene.
- Nuevo recurso `catalogos`: `listar()` (`GET /catalogos`) y `consultar($nombre, ['q' => ..., 'ruc' => ...])`
  (`GET /catalogos/{nombre}`).
- Reintentos: un error cuyo cuerpo diga `reintentable: false` no se reintenta nunca, aunque sea 429/409/5xx.
  El `429 LIMITE_PRUEBAS_DIARIO` (tope diario de RUCs demo) lanza `LimiteDePeticiones` tras una sola
  petición, con `codigo` `LIMITE_PRUEBAS_DIARIO` y `reintentable` false.
- Anulaciones: también boletas (resumen diario). La respuesta trae `tipo` (`baja` | `resumen`) e
  `identificador`; mezclar facturas y boletas → `ErrorDeValidacion` con `codigo` `ANULACION_MIXTA`.
- `FactuSmart\Codigos`: nuevas constantes `LIMITE_PRUEBAS_DIARIO`, `ANULACION_MIXTA` y
  `LLAVE_DE_PRUEBA_EN_PRODUCCION`.
- RUCs demo: no cobran crédito (`credito_cobrado: false`). La prueba de idempotencia ya no mira el saldo.
- `comprobantes->descargar($id, 'pdf', ['formato' => 'ticket'])`: PDF en `a4`, `a5`, `ticket` (= `ticket80`),
  `ticket80`, `ticket58` o `ticket50` (`?formato=`). Sin `formato` no se manda el parámetro (A4 de siempre).
  `formato` con `xml`/`cdr` o fuera de la lista → `ErrorDeValidacion` local (422, `DATOS_INVALIDOS`,
  `campos['formato']`) sin petición. Constante `Comprobantes::FORMATOS_PDF`.
- Nuevo recurso `establecimientos` (anexos): `listar()` (`GET /establecimientos`, se reintenta como GET) y
  `crear($datos)` (`POST /establecimientos`, `ruc` en el cuerpo, **nunca** se reintenta solo).
- Nuevo `catalogos->buscarProductoSunat($q, ['limite' => ..., 'ruc' => ...])`
  (`GET /catalogos/productos-sunat`, catálogo 25); también `nivel`.
- `FactuSmart\Codigos`: nuevas constantes `PDF_NO_GENERADO`, `LIMITE_ESTABLECIMIENTOS` y `CATALOGO_NO_DISPONIBLE`.

## 0.1.0 — 2026-10-14

- Primera versión publicada: cliente con todos los endpoints del API de integradores,
  `calcularComprobante()`, reintentos con `Idempotency-Key` y excepciones por estado HTTP.
