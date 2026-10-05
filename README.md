# ecuafact/sdk (PHP)

Cliente oficial en PHP del API publico de facturacion electronica Ecuafact (contrato v1). Cubre
autenticacion, idempotencia, reintentos y firma de webhooks. Compatible con PHP 8.2+.

## Instalacion

```bash
composer require ecuafact/sdk
```

## Requisitos

- PHP 8.2+ con `ext-curl` y `ext-json`.

## Uso

```php
<?php

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;

$client = new EcuafactClient(new EcuafactClientOptions(
    baseAddress: 'https://staging-api.mynexusapi.com/', // sandbox; produccion: https://api.mynexusapi.com/
    apiKey: getenv('ECUAFACT_API_KEY'),
    identificacion: '1790012345001', // solo integraciones de un RUC
));

$comprobante = new ComprobanteRequest();
$comprobante->origenReferencia = 'MiERP';
$comprobante->referenciaExterna = 'FACTURA-2026-0001';
$comprobante->infoTributaria = new InfoTributaria();
$comprobante->infoTributaria->ruc = '1790012345001';
$comprobante->infoTributaria->codDoc = '01';
$comprobante->infoTributaria->estab = '002';
$comprobante->infoTributaria->ptoEmi = '001';
$comprobante->infoTributaria->secuencial = '000000123';
// $comprobante->info = ...; $comprobante->detalles = [...];

$resultado = $client->emitir($comprobante);
echo $resultado->admission->idOperacion . ' ' . $resultado->admission->codigo . PHP_EOL;
```

### Multi-RUC

```php
$ruc = $client->para('1790099987001');
$pagina = $ruc->listarEmitidos();
```

## Idempotencia

`idempotencyKey` es opcional: si no se envia, el SDK la genera, la reutiliza en los reintentos y la
devuelve en `resultado->idempotencyKey` (y en el error, como `EcuafactApiException->idempotencyKey`).
Al reintentar, **reutiliza la clave devuelta**. La correlacion de la respuesta queda en
`resultado->correlationId` (o `EcuafactApiException->idSeguimiento` en error).

Los fallos transitorios (`408/425/429/5xx`) se reintentan con la misma clave respetando `Retry-After`;
el resto de los 4xx no.

## Opciones

| Opcion | Default | Descripcion |
|---|---|---|
| `baseAddress` | — | Direccion base del API (obligatoria) |
| `apiKey` | — | Credencial `X-Api-Key` (obligatoria) |
| `identificacion` | `null` | RUC por defecto (opcional) |
| `timeout` | `100.0` | Tiempo maximo por intento (segundos) |
| `userAgent` | `Ecuafact.Sdk/1.0` | User-Agent |
| `retryTransientFailures` | `true` | Reintenta `408/425/429/5xx` |
| `maxAttempts` | `3` | Intentos por solicitud |
| `respectRetryAfter` | `true` | Respeta `Retry-After` en `429`/`503` |
| `maxRetryDelaySeconds` | `60.0` | Espera maxima entre reintentos |

## Errores

Los errores del API se lanzan como `EcuafactApiException` (`codigo`, `mensaje`, `estadoHttp`,
`idSeguimiento`, `errores`, `idempotencyKey`). Los errores locales de configuracion o transporte son
`EcuafactSdkException`.

## Webhooks

```php
use Ecuafact\Sdk\WebhookSignature;

$valido = WebhookSignature::verify($secreto, $cabecera, $cuerpoCrudo, time(), 300);
```

## Documentacion

- [Guia del SDK PHP](https://docsapi.ecuafact.com/v1/guias/sdk-php)

## Licencia

MIT.
