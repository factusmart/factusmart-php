<?php

declare(strict_types=1);

namespace FactuSmart\Excepciones;

/** HTTP 403: el RUC no pertenece a la cuenta o está desactivado. */
class RucNoAutorizado extends FactuSmartError
{
}
