<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\GuiaRemisionRequest;
use Ecuafact\Sdk\Contracts\LiquidacionCompraRequest;
use Ecuafact\Sdk\Contracts\NotaCreditoRequest;
use Ecuafact\Sdk\Contracts\NotaDebitoRequest;
use Ecuafact\Sdk\Contracts\RetencionRequest;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\Support\Hydrator;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Simulacion local de los seis tipos completos: se arma, desde el esquema del contrato, un cuerpo con TODAS las
 * propiedades llenas, se lee en la clase del tipo, y se emite y valida contra un transporte falso. El cuerpo que
 * sale debe ser identico al del esquema, en la ruta del tipo.
 */
final class SimulacionTiposDesdeSpecTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    public static function setUpBeforeClass(): void
    {
        $ruta = __DIR__ . '/../../../examples/openapi-v1.json';
        if (is_file($ruta)) {
            $json = json_decode((string) file_get_contents($ruta), true);
            self::$spec = is_array($json) ? $json : null;
        }
    }

    protected function setUp(): void
    {
        if (self::$spec === null) {
            $this->markTestSkipped('No esta examples/openapi-v1.json (paquete publicado fuera del repo).');
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function tipos(): iterable
    {
        yield 'factura' => ['FacturaRequestDto', FacturaRequest::class, 'facturas'];
        yield 'liquidacion de compra' => ['LiquidacionCompraRequestDto', LiquidacionCompraRequest::class, 'liquidaciones-compra'];
        yield 'nota de credito' => ['NotaCreditoRequestDto', NotaCreditoRequest::class, 'notas-credito'];
        yield 'nota de debito' => ['NotaDebitoRequestDto', NotaDebitoRequest::class, 'notas-debito'];
        yield 'guia de remision' => ['GuiaRemisionRequestDto', GuiaRemisionRequest::class, 'guias-remision'];
        yield 'retencion' => ['ComprobanteRetencionRequestDto', RetencionRequest::class, 'retenciones'];
    }

    #[DataProvider('tipos')]
    public function testCuerpoCompletoDelEsquemaViajaIntegroPorEmitirYValidar(string $esquema, string $clase, string $ruta): void
    {
        $cuerpo = $this->ejemplo(['$ref' => '#/components/schemas/' . $esquema]);
        $solicitud = Hydrator::hydrate($clase, json_decode(json_encode($cuerpo), false));
        self::assertInstanceOf($clase, $solicitud);

        $transport = new FakeTransport(
            new TransportResponse(200, '{"valido":true}'),
            new TransportResponse(202, '{"estado":"recibido","codEstado":1002,"idOperacion":"op-1"}'),
            new TransportResponse(200, '{"valido":true}')
        );
        $client = new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave-de-prueba',
            identificacion: '0123456789',
            transport: $transport,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
        ));

        self::assertTrue($client->validar($solicitud)->valido);
        $client->emitir($solicitud, 'K-SIM');

        $base = 'https://api.example/v1/contribuyentes/0123456789/' . $ruta;
        self::assertSame($base . '/validar', $transport->peticiones[0]['url']);
        self::assertSame($base, $transport->peticiones[1]['url']);
        foreach ([0, 1] as $i) {
            $enviado = json_decode((string) $transport->peticiones[$i]['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(self::canonico($cuerpo), self::canonico($enviado), $esquema . ' (peticion ' . $i . ')');
        }
    }

    /**
     * Valor de ejemplo con todas las propiedades llenas.
     *
     * @param array<string, mixed> $def
     */
    private function ejemplo(array $def, string $nombre = ''): mixed
    {
        if (isset($def['oneOf'])) {
            foreach ($def['oneOf'] as $alternativa) {
                if (($alternativa['type'] ?? null) !== 'null') {
                    return $this->ejemplo($alternativa, $nombre);
                }
            }
        }
        if (isset($def['$ref'])) {
            $esquema = self::$spec['components']['schemas'][basename($def['$ref'])];
            $obj = [];
            foreach ($esquema['properties'] as $propiedad => $hija) {
                $obj[$propiedad] = $this->ejemplo($hija, $propiedad);
            }
            return $obj;
        }
        $tipo = $def['type'] ?? 'string';
        if (is_array($tipo)) {
            $tipo = array_values(array_diff($tipo, ['null']))[0] ?? 'string';
        }
        return match ($tipo) {
            'array' => [$this->ejemplo($def['items'], $nombre)],
            'number' => 12.5,
            'integer' => 3,
            'boolean' => true,
            default => 'v-' . $nombre,
        };
    }

    private static function canonico(mixed $valor): mixed
    {
        if (is_array($valor)) {
            $out = [];
            foreach ($valor as $k => $v) {
                $out[$k] = self::canonico($v);
            }
            if (!array_is_list($out)) {
                ksort($out);
            }
            return $out;
        }
        return is_int($valor) || is_float($valor) ? 'n:' . (float) $valor : $valor;
    }
}
