<?php

declare(strict_types=1);

namespace FactuSmart;

/**
 * Códigos de error que devuelve el API en `codigo` (y el SDK en $e->codigo).
 *
 *   if ($e->codigo === FactuSmart\Codigos::SIN_CREDITOS) { ... }
 *
 * Si el API devuelve un estado HTTP sin código propio, el código es "ERROR_<status>" (p.ej. "ERROR_418").
 */
final class Codigos
{
    public const NO_AUTENTICADO = 'NO_AUTENTICADO';
    public const PLAN_VENCIDO = 'PLAN_VENCIDO';
    public const SIN_CREDITOS = 'SIN_CREDITOS';
    public const RUC_NO_AUTORIZADO = 'RUC_NO_AUTORIZADO';
    public const RUC_DESACTIVADO = 'RUC_DESACTIVADO';
    public const SIN_PERMISO = 'SIN_PERMISO';
    public const NO_ENCONTRADO = 'NO_ENCONTRADO';
    public const RUTA_NO_ENCONTRADA = 'RUTA_NO_ENCONTRADA';
    public const METODO_NO_PERMITIDO = 'METODO_NO_PERMITIDO';
    public const EMISION_EN_CURSO = 'EMISION_EN_CURSO';
    public const CONFLICTO = 'CONFLICTO';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const RUC_REQUERIDO = 'RUC_REQUERIDO';
    public const RUC_SIN_TENANT = 'RUC_SIN_TENANT';
    public const CERTIFICADO_NO_CONFIGURADO = 'CERTIFICADO_NO_CONFIGURADO';
    public const ERROR_EMISION = 'ERROR_EMISION';
    public const RECHAZADO_SUNAT = 'RECHAZADO_SUNAT';
    public const REENVIO_FALLIDO = 'REENVIO_FALLIDO';
    public const SUNAT_NO_DISPONIBLE = 'SUNAT_NO_DISPONIBLE';
    public const LIMITE_PETICIONES = 'LIMITE_PETICIONES';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    private function __construct()
    {
    }
}
