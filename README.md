# ecuafact/sdk (PHP)

Cliente oficial en PHP del API publico de facturacion electronica Ecuafact (contrato v1). Cubre
autenticacion, idempotencia, reintentos configurables, espera del resultado, recorrido de paginas,
logo del perfil y firma de webhooks. Compatible con PHP 8.2+.

## Instalacion

```bash
composer require ecuafact/sdk
```

## Requisitos

- [ ] PHP 8.2+ con `ext-curl` y `ext-json`.
- [ ] Una API Key de integracion.
- [ ] La URL base del despliegue (sandbox `https://staging-api.mynexusapi.com/`).

## Uso

La `identificacion` de cada ruta es el valor `identificacion` que devuelve `GET /v1/contexto` para tu
contribuyente (por ejemplo `0123456789`).

```php
<?php

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;

// Sin argumentos, el SDK lee ECUAFACT_API_KEY y ECUAFACT_BASE_URL del entorno.
$client = new EcuafactClient(new EcuafactClientOptions(
    identificacion: '0123456789', // solo integraciones de un contribuyente
));

$comprobante = new ComprobanteRequest();
$comprobante->origenReferencia = 'MiERP';
$comprobante->referenciaExterna = 'FACTURA-2026-0001';
$comprobante->infoTributaria = new InfoTributaria();
$comprobante->infoTributaria->ruc = '0123456789001'; // identificacionCompleta de GET /v1/contexto
$comprobante->infoTributaria->codDoc = '01';
$comprobante->infoTributaria->estab = '002';
$comprobante->infoTributaria->ptoEmi = '001';
// $comprobante->info = ...; $comprobante->detalles = [...];

$resultado = $client->emitir($comprobante);          // 202: admitido, no autorizado todavia
$operacion = $client->esperarResultado($resultado->admission->idOperacion);
if ($operacion->esFinal() && $operacion->esAutorizado()) {
    echo $operacion->claveAcceso . PHP_EOL;
}
```

### Multi-RUC

```php
$ruc = $client->para('0123456789');
$pagina = $ruc->listarEmitidos();
$ride = $ruc->descargarRide($claveAcceso);
$perfil = $ruc->actualizarLogo(file_get_contents('logo.png'), 'image/png', 'logo.png');
```

La vista expone `emitir`, `listarEmitidos`, `iterarEmitidos`, `descargarRide`, `descargarXml`,
`enviarCorreo`, `getPerfil`, `actualizarPerfil`, `actualizarLogo`, `esperarResultado` y `getConsumo`.

## Variables de entorno

| Variable | Se usa cuando | Ejemplo |
|---|---|---|
| `ECUAFACT_API_KEY` | No pasas `apiKey` (o pasas `null`/`false`) | `ek_live_...` |
| `ECUAFACT_BASE_URL` | No pasas `baseAddress` (o pasas `null`/`false`) | `https://staging-api.mynexusapi.com/` |

El SDK no distingue ambientes: el ambiente lo define la URL. La URL debe ser absoluta (`http` o
`https`) y **no** debe terminar en `/v1`: el SDK agrega `/v1` a cada ruta. Una URL invalida o una
API Key con espacios al inicio o al final o con caracteres de control lanza
`EcuafactConfigurationException`.

## Importes exactos

Importes, cantidades, precios y tarifas de la solicitud aceptan `float` o `string` decimal. El string
(`'11.50'`) viaja como numero JSON literal (`11.50`), sin pasar por float. Un float se envia con 15
digitos significativos: `0.1 + 0.2` viaja como `0.3`. Un string que no sea decimal (`'1e3'`) lanza
`EcuafactSdkException` antes de enviar. En las respuestas, `Comprobante::$total` y
`CatalogoItem::$tarifa` se leen como `float` (precision de double).

`Hydrator::hydrate()` convierte cada valor al tipo declarado y, si no puede, lanza
`EcuafactSdkException` (nunca un `TypeError`).

## Idempotencia

`idempotencyKey` es opcional: si no se envia, el SDK la genera, la reutiliza en los reintentos y la
devuelve en `resultado->idempotencyKey` (y en el error, como `->idempotencyKey`). Formato: solo letras,
digitos y `- _ . : /`, maximo 128 caracteres; otra clave lanza `EcuafactSdkException` sin llamar al API.
Al reintentar, **reutiliza la clave devuelta**. La correlacion queda en `resultado->correlationId`
(o `->idSeguimiento` en el error).

`getOperacion`, `consultarOperacion` y `esperarResultado` exigen un `idOperacion` UUID.

## Reintentos

Se reintentan solo solicitudes idempotentes (GET, o POST/PUT con `Idempotency-Key`) ante errores de red
y los estados de `retryableStatusCodes` (`408, 425, 500, 502, 503, 504`).

- `429` con `codigo` `501` (cupo agotado): **nunca** se reintenta.
- `429` con `codigo` `104` (limite de solicitudes): solo con `retryRateLimited: true`, respetando
  `Retry-After` y `maxRetryDelaySeconds`.

## Opciones

| Opcion | Default | Descripcion |
|---|---|---|
| `baseAddress` | `ECUAFACT_BASE_URL` | URL base del despliegue, sin `/v1` |
| `apiKey` | `ECUAFACT_API_KEY` | Credencial `X-Api-Key`; no se muestra en `var_dump`, `print_r` ni trazas |
| `identificacion` | `null` | Contribuyente por defecto (opcional) |
| `timeout` | `100.0` | Tiempo maximo por intento, en segundos (admite fracciones) |
| `userAgent` | `Ecuafact.Sdk/1.0` | User-Agent |
| `retryTransientFailures` | `true` | `false` desactiva todos los reintentos |
| `maxAttempts` | `3` | Intentos por solicitud (minimo 1) |
| `respectRetryAfter` | `true` | Espera al menos lo que indica `Retry-After` |
| `maxRetryDelaySeconds` | `60.0` | Espera maxima entre reintentos (`0` = sin tope) |
| `retryRateLimited` | `false` | Reintenta el `429`/`104` |
| `retryableStatusCodes` | `[408, 425, 500, 502, 503, 504]` | Estados HTTP reintentables |
| `retryBaseDelaySeconds` | `0.2` | Espera base entre reintentos |
| `retryBackoff` | `RetryBackoff::Linear` | `Linear` (base x intento) o `Exponential` (base x 2^(intento-1)); acepta `'linear'`/`'exponential'` |
| `retryJitterSeconds` | `0.05` | Variacion aleatoria maxima (por intento en `Linear`) |
| `totalTimeoutSeconds` | `null` | Tope de toda la llamada, incluidos reintentos (`EcuafactTimeoutException`) |
| `transport` | `CurlTransport` | Transporte propio (`TransportInterface`) o `Psr18Transport` |
| `connectTimeout` | `null` | Tiempo maximo de conexion, en segundos (solo cURL) |
| `proxy` | `null` | URL del proxy (solo cURL) |
| `caInfo` | `null` | Bundle de CA en PEM (solo cURL) |
| `verifyPeer` | `true` | `false` desactiva la validacion TLS; solo pruebas locales (solo cURL) |
| `curlOptions` | `[]` | Opciones `CURLOPT_*` extra (solo cURL) |

El transporte cURL reutiliza el mismo handle (keep-alive). Para usar Guzzle o Symfony HttpClient,
instala `psr/http-client` y `psr/http-factory` y pasa
`transport: new Psr18Transport($cliente, $requestFactory, $streamFactory)`; el timeout, el proxy y TLS
los configura tu cliente.

## Opciones por llamada

Todos los metodos que llaman al API (tambien `para()`, `esperarResultado` e `iterarEmitidos`) aceptan
como ultimo argumento `?RequestOptions $opciones`. Solo afectan a esa llamada.

```php
use Ecuafact\Sdk\RequestOptions;

$opciones = new RequestOptions(
    timeout: 20.0,            // por intento; reemplaza al timeout del cliente
    totalTimeout: 45.0,       // tope de la llamada, incluidos reintentos
    correlationId: 'PED-2026-0457', // cabecera X-Correlation-Id: 1-64 de A-Z a-z 0-9 . _ -
    reintentos: true,         // false: sin reintentos en esta llamada
);
$resultado = $client->emitirEn('0123456789', $comprobante, 'FACTURA-2026-0001', $opciones);
echo $resultado->correlationId; // la correlacion que devolvio el API
```

- Un `correlationId` invalido o un tiempo `<= 0` lanza `EcuafactConfigurationException`.
- La correlacion queda en `EmisionResultado::$correlationId`, en `EcuafactApiException::$idSeguimiento`
  y en `EcuafactConnectionException::$correlationId` / `EcuafactTimeoutException::$correlationId`.
- `timeout` por llamada se aplica con el transporte cURL (o uno que implemente
  `TimeoutOverrideTransportInterface`); con un transporte PSR-18 lo controla tu cliente HTTP.
- PHP no tiene cancelacion cooperativa: acota la llamada con `timeout` o `totalTimeout`.

## Constructores de comprobantes

Opcionales. Producen el mismo `ComprobanteRequest`; puedes seguir armandolo a mano.

```php
use Ecuafact\Sdk\Builders\ComprobanteBuilder;
use Ecuafact\Sdk\Builders\Iva;
use Ecuafact\Sdk\Builders\Tarifa;

$builder = ComprobanteBuilder::factura('0123456789001', '002', '001')
    ->referencias('MiERP', 'FACTURA-2026-0001')
    ->fechaEmision('07/10/2026')
    ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS')
    ->linea('SERV-001', 'CONSULTORIA', 3, '12.50', '2.50', Iva::TARIFA_15)
    ->linea('PRD-ICE-01', 'PRODUCTO CON ICE', 1, '100.00', 0, Iva::TARIFA_15, Tarifa::ice('3011', 10))
    ->pago('01')            // sin total: recibe lo que falta del importeTotal
    ->calcularTotales();    // opcional: rellena solo lo que esta vacio

$inconsistencias = $builder->validar(); // Inconsistencia[] {campo, mensaje, esperado, actual}; no lanza
$resultado = $client->emitirEn('0123456789', $builder->construir(), 'FACTURA-2026-0001');
```

- Tipos: `factura`, `liquidacionCompra`, `notaCredito`, `notaDebito`, `guiaRemision`, `retencion`.
- Lo que asignas nunca se sobrescribe (`importeTotal()`, `detalle(Detalle)`, `configurar(fn)`...).
- Reglas del API: subtotal `redondear(cantidad x precio - descuento)`; IVA sobre la base sin redondear
  mas el ICE; cabecera agrupada por `codigo` + `codigoPorcentaje` (IVA y luego ICE) sumando las lineas;
  total = subtotal + IVA + ICE + propina.
- Precision: `new Precision(escalaImportes: 2, escalaCantidades: 6, redondeo: ModoRedondeo::HALF_UP)`
  como ultimo argumento de la fabrica.
- Sin constructor: `ComprobanteBuilder::calcularTotales($request)` (devuelve una copia) y
  `ComprobanteBuilder::validarComprobante($request)`.
- Calculo exacto: usa `bcmath` si esta instalado y, si no, aritmetica decimal propia sobre strings.

## Recorrer paginas

```php
use Ecuafact\Sdk\ListadoRequest;

$filtros = (new ListadoRequest())->desde(new DateTimeImmutable('2026-10-01'))->hasta('2026-10-07');
foreach ($client->iterarEmitidosEn('0123456789', $filtros) as $comprobante) {
    echo $comprobante->claveAcceso . PHP_EOL;
}
```

`desde`/`hasta` son fechas de calendario de Ecuador (`Y-m-d`). Un `DateTimeInterface` se formatea como
`Y-m-d` en su propia zona horaria. `iterarEmitidos` pide cada pagina solo cuando la necesitas y para con
`hayMas = false` o en la pagina 10000.

## Estados tipados

`Operation::estadoOperacion()` (`EstadoOperacion`), `Operation::estadoAutorizacionTipado()`
(`EstadoAutorizacion`), `Operation::tipoComprobante()` (`TipoComprobante`), `Operation::esFinal()` y
`Operation::esAutorizado()`. Un valor nuevo del API se lee como `Desconocido` (o `null` en
`TipoComprobante`); los campos string originales no cambian.

## Errores

| Excepcion | Cuando | Que hacer |
|---|---|---|
| `EcuafactAuthException` | 401/403 | Revisa la API Key y el contribuyente autorizado |
| `EcuafactValidationException` | 400/422 | Corrige los datos segun `errores` |
| `EcuafactNotFoundException` | 404 | Revisa la identificacion, la clave o el id |
| `EcuafactConflictException` | 409 | Misma `Idempotency-Key` con otro contenido, o comprobante duplicado |
| `EcuafactRateLimitException` | 429/`104` | Espera `retryAfter` segundos y repite |
| `EcuafactQuotaException` | 429/`501` | Compra cupo; no repitas |
| `EcuafactServerException` | 5xx | Repite con la misma `Idempotency-Key` si `esReintentable` |
| `EcuafactConnectionException` | Sin respuesta HTTP | Repite con la misma `Idempotency-Key` |
| `EcuafactTimeoutException` | Tiempo agotado | Consulta la operacion o repite con la misma clave |
| `EcuafactConfigurationException` | Opcion invalida | Corrige la configuracion |
| `EcuafactWebhookException` | Firma de webhook invalida | Responde 400 y no proceses el evento |

Las de API heredan de `EcuafactApiException` (`codigo`, `mensaje`, `errores`, `estadoHttp`,
`idSeguimiento`, `idempotencyKey`, `retryAfter`, `esReintentable`); todas heredan de
`EcuafactSdkException`.

## Webhooks

```php
use Ecuafact\Sdk\Contracts\TipoEventoWebhook;
use Ecuafact\Sdk\EcuafactWebhookException;
use Ecuafact\Sdk\WebhookSignature;

$cuerpo = file_get_contents('php://input');
try {
    // Uno o varios secretos (rotacion); tolerancia por defecto 300 s.
    $evento = WebhookSignature::construirEvento([$secretoActual, $secretoAnterior],
        $_SERVER['HTTP_ECUAFACT_SIGNATURE'] ?? '', $cuerpo);
} catch (EcuafactWebhookException) {
    http_response_code(400);
    return;
}
if ($evento->eventType === TipoEventoWebhook::DocumentoAutorizado) {
    // $evento->idOperacion, $evento->claveAcceso
}
```

`WebhookSignature::verify($secretos, $cabecera, $cuerpo)` devuelve `false` (nunca lanza) ante una
cabecera malformada, vencida o con firma distinta.

## Documentacion

- [Guia del SDK PHP](https://docsapi.ecuafact.com/v1/guias/sdk-php)

## Licencia

MIT.
