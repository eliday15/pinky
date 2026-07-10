<?php

namespace App\Services\Cfdi;

/**
 * Contrato con el PAC (Proveedor Autorizado de Certificación) que timbra los
 * CFDI de nómina ante el SAT. Implementaciones: FacturamaDriver (real, con
 * sandbox) y FakePacDriver (tests). Intercambiable vía config
 * services.cfdi.driver.
 */
interface PacProviderInterface
{
    /**
     * Timbra un recibo de nómina.
     *
     * Args:
     *     payload: JSON del CFDI de nómina (formato del PAC).
     *
     * Returns:
     *     ['uuid' => folio fiscal, 'pac_id' => id del documento en el PAC,
     *      'xml' => XML timbrado (string)].
     *
     * Throws:
     *     \RuntimeException si el PAC rechaza el documento (mensaje del PAC).
     */
    public function stamp(array $payload): array;

    /**
     * Cancela un CFDI timbrado.
     *
     * Args:
     *     pacId: Id del documento en el PAC.
     *     motive: Motivo SAT ('02' = comprobante con errores sin relación).
     */
    public function cancel(string $pacId, string $motive = '02'): void;

    /**
     * Descarga el PDF del CFDI timbrado (binario).
     */
    public function getPdf(string $pacId): string;
}
