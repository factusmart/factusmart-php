<?php

declare(strict_types=1);

namespace FactuSmart\Http;

/** Uso interno: el transporte no obtuvo respuesta HTTP. Se reintenta y luego se convierte en FactuSmartError. */
final class ErrorDeRed extends \RuntimeException
{
}
