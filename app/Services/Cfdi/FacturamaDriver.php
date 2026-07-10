<?php

namespace App\Services\Cfdi;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

/**
 * Driver de Facturama (API Web, auth Basic): timbra recibos de nómina CFDI
 * 4.0 con complemento 1.2. Usamos el API de ALTO NIVEL (se manda el JSON de
 * la nómina y Facturama arma/sella el XML) para no mantener XML propio.
 *
 * Sandbox: https://apisandbox.facturama.mx (FACTURAMA_SANDBOX=true, default).
 * Producción: https://api.facturama.mx (tras subir el CSD real a la cuenta).
 * Endpoints (docs https://apisandbox.facturama.mx/guias): POST /3/cfdis para
 * emitir, DELETE /cfdi/{id}?type=payroll... — los paths exactos se confirman
 * contra la guía al probar sandbox; están centralizados aquí.
 */
class FacturamaDriver implements PacProviderInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.facturama.sandbox', true)
            ? 'https://apisandbox.facturama.mx'
            : 'https://api.facturama.mx';
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.facturama.user'),
            (string) config('services.facturama.password'),
        )->baseUrl($this->baseUrl)->timeout(60)->acceptJson();
    }

    public function stamp(array $payload): array
    {
        $response = $this->http()->post('/3/cfdis', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException($this->formatError($response->status(), $response->json() ?? $response->body()));
        }

        $data = $response->json();
        $pacId = (string) ($data['Id'] ?? '');
        $uuid = (string) (data_get($data, 'Complement.TaxStamp.Uuid') ?? $data['Uuid'] ?? '');

        // El XML se descarga por separado (endpoint de descarga por id).
        $xml = '';
        if ($pacId !== '') {
            $xmlResponse = $this->http()->get("/cfdi/xml/issued/{$pacId}");
            if ($xmlResponse->successful()) {
                $content = $xmlResponse->json('Content');
                $xml = $content ? base64_decode((string) $content, true) ?: '' : $xmlResponse->body();
            }
        }

        return ['uuid' => $uuid, 'pac_id' => $pacId, 'xml' => $xml];
    }

    public function cancel(string $pacId, string $motive = '02'): void
    {
        $response = $this->http()->delete("/cfdi/{$pacId}", [
            'type' => 'issued',
            'motive' => $motive,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->formatError($response->status(), $response->json() ?? $response->body()));
        }
    }

    public function getPdf(string $pacId): string
    {
        $response = $this->http()->get("/cfdi/pdf/issued/{$pacId}");

        if (! $response->successful()) {
            throw new \RuntimeException($this->formatError($response->status(), $response->json() ?? $response->body()));
        }

        $content = $response->json('Content');

        return $content ? (base64_decode((string) $content, true) ?: '') : $response->body();
    }

    /**
     * Aplana la respuesta de error de Facturama (ModelState/Message) a una
     * línea legible para guardar en pac_response.
     */
    private function formatError(int $status, mixed $body): string
    {
        if (is_array($body)) {
            $parts = [];
            if (! empty($body['Message'])) {
                $parts[] = (string) $body['Message'];
            }
            foreach ((array) ($body['ModelState'] ?? []) as $field => $errors) {
                $parts[] = $field.': '.implode(' ', (array) $errors);
            }
            $body = $parts ? implode(' | ', $parts) : json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        return "Facturama HTTP {$status}: ".mb_substr((string) $body, 0, 900);
    }
}
