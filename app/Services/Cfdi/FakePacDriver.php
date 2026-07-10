<?php

namespace App\Services\Cfdi;

use Illuminate\Support\Str;

/**
 * PAC falso para tests y desarrollo sin credenciales: "timbra" generando un
 * UUID local y un XML mínimo. Registra las llamadas para aserciones.
 */
class FakePacDriver implements PacProviderInterface
{
    /** @var array<int, array> */
    public array $stamped = [];

    /** @var array<int, array{pac_id: string, motive: string}> */
    public array $canceled = [];

    /** Si se asigna, stamp() lanza esta excepción (simular rechazo del PAC). */
    public ?\RuntimeException $failWith = null;

    public function stamp(array $payload): array
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->stamped[] = $payload;
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'pac_id' => 'fake-'.count($this->stamped),
            'xml' => '<?xml version="1.0"?><cfdi:Comprobante fake="true" uuid="'.$uuid.'"/>',
        ];
    }

    public function cancel(string $pacId, string $motive = '02'): void
    {
        $this->canceled[] = ['pac_id' => $pacId, 'motive' => $motive];
    }

    public function getPdf(string $pacId): string
    {
        return '%PDF-1.4 fake';
    }
}
