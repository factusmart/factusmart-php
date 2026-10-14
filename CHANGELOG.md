# Cambios

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
