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

## 0.1.0 — 2026-10-14

- Primera versión publicada: cliente con todos los endpoints del API de integradores,
  `calcularComprobante()`, reintentos con `Idempotency-Key` y excepciones por estado HTTP.
