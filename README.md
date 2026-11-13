# ecuafact/sdk (PHP)

Cliente oficial en PHP del API publico de facturacion electronica Ecuafact (contrato v1). Cubre
autenticacion, idempotencia, reintentos configurables, espera del resultado, logo del perfil y firma de
webhooks. Compatible con PHP 8.2+.

Versión preliminar actual: `v1.0.0-beta8`.

## Instalacion

```bash
composer require "ecuafact/sdk:^1.0@beta"
```

El SDK esta en version preliminar: sin `@beta`, Composer busca una version estable y no la encuentra.

## Requisitos

- [ ] PHP 8.2+ con `ext-curl` y `ext-json`.
- [ ] Una API Key de integracion.
- [ ] La URL base del despliegue (sandbox `https://staging-api.mynexusapi.com/`).

## Uso

La `identificacion` de cada ruta es el valor `identificacion` que devuelve `GET /v1/contexto` para tu
contribuyente (por ejemplo `0123456789`).

```php
<?php

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;

// Sin argumentos, el SDK lee ECUAFACT_API_KEY y ECUAFACT_BASE_URL del entorno.
$client = new EcuafactClient(new EcuafactClientOptions(
    identificacion: '0123456789001', // identificacion de GET /v1/contexto (RUC de 13 digitos); opcional
));

$comprobante = new FacturaRequest();
$comprobante->origenReferencia = 'MiERP';
$comprobante->referenciaExterna = 'FACTURA-2026-0001';
$comprobante->infoTributaria = new InfoTributaria();
$comprobante->infoTributaria->ruc = '0123456789001'; // identificacion de GET /v1/contexto
$comprobante->infoTributaria->estab = '002';
$comprobante->infoTributaria->ptoEmi = '001';
// $comprobante->info = new InfoFactura(); $comprobante->detalles = [new DetalleCompraVenta(), ...];

$resultado = $client->emitir($comprobante);          // 202 con estado "recibido": aun no autorizado
$operacion = $client->esperarResultado($resultado->admission->idOperacion);
if ($operacion->esAutorizado()) {
    echo $operacion->claveAcceso . PHP_EOL;
} elseif ($operacion->esFinal()) {
    // codEstado numerico (por ejemplo 3001), msgError general y errores con el detalle
    echo $operacion->estado . ' ' . $operacion->codEstado . ': ' . $operacion->msgError . PHP_EOL;
    print_r($operacion->errores);
}
```

Cada tipo de comprobante tiene su propia clase de solicitud, igual que tiene su propio endpoint, y `emitir`,
`emitirEn`, `validar` y `validarEn` eligen la ruta por la clase que les pasas: `FacturaRequest` va a `facturas`,
`LiquidacionCompraRequest` a `liquidaciones-compra`, `NotaCreditoRequest` a `notas-credito`, `NotaDebitoRequest` a
`notas-debito`, `GuiaRemisionRequest` a `guias-remision` y `RetencionRequest` a `retenciones`. No hay campo de tipo
en el cuerpo ni opcion de tipo en la llamada. Cada clase tiene solo las propiedades de su esquema del contrato y
usa su `Info*` (`InfoFactura`, `InfoLiquidacionCompra`, `InfoNotaCredito`, `InfoNotaDebito`, `InfoGuiaRemision`,
`InfoComprobanteRetencion`) y sus lineas (`DetalleCompraVenta` para factura y liquidacion, `DetalleNotaCredito`,
`Motivo`, `Destinatario` con `DetalleGuia`, y `DocsSustento` con `ImpuestoDocSustento` y `RetencionLinea`), con los
modelos comunes `InfoTributaria`, `Pago`, `Impuesto` y `CampoAdicional`. En el docblock de cada propiedad,
`Obligatorio.` marca las que el contrato exige; el SDK no las valida, lo hace el API.

### Multi-RUC

```php
$ruc = $client->para('0123456789001');
$operacion = $ruc->esperarResultado($idOperacion);
$ride = $ruc->descargarRide($claveAcceso);
$perfil = $ruc->actualizarLogo(file_get_contents('logo.png'), 'image/png', 'logo.png');
```

La vista expone `emitir`, `validar`, `descargarRide`, `descargarXml`, `getPerfil`, `actualizarPerfil`,
`actualizarLogo`, `esperarResultado`, `listarOperaciones` y `getConsumo`.

## Listado de comprobantes y salud

`listarOperaciones` (`GET /v1/operaciones`, sin consumir cupo) devuelve una `PaginaComprobantes` (`comprobantes`,
`pagina`, `tamanoPagina`, `hayMas`). Filtros: `desde` y `hasta` (`yyyy-MM-dd`), `tipoDocumento` (codigo o nombre),
`buscar`, `pagina` desde 1 y `tamanoPagina` de 1 a 100. `obtenerSalud()` llama a `GET /salud` (sin API Key) y devuelve
`true`; con una respuesta que no es 2xx lanza la excepcion que corresponde.

```php
$pagina = 1;
do {
    $resultado = $client->listarOperaciones('0123456789001', desde: '2026-11-01', pagina: $pagina);
    foreach ($resultado->comprobantes as $fila) {
        echo $fila->numeroDocumento . ' ' . $fila->estado . PHP_EOL;
    }
    $pagina++;
} while ($resultado->hayMas);
```

## Destinos de webhooks

Administra los destinos desde el SDK: `listarWebhookDestinos`, `crearWebhookDestino`, `getWebhookDestino`,
`actualizarWebhookEventos`, `rotarWebhookSecreto`, `probarWebhookDestino`, `pausarWebhookDestino`,
`reanudarWebhookDestino`, `deshabilitarWebhookDestino`, `listarWebhookEntregas`, `listarWebhookIntentos` y
`reintentarWebhookEntrega`. Los identificadores son UUID. El secreto de firma (`secretoUnaVez`) llega solo al crear el
destino o al rotar el secreto: guardalo en ese momento. Estos metodos no se reintentan automaticamente.

```php
use Ecuafact\Sdk\Contracts\WebhookDestinoCrearRequest;

$solicitud = new WebhookDestinoCrearRequest();
$solicitud->url = 'https://miempresa.com/webhooks/ecuafact';
$solicitud->eventos = ['document.authorized', 'document.rejected'];
$destino = $client->crearWebhookDestino($solicitud);
$client->probarWebhookDestino($destino->publicId);
```

## Variables de entorno

| Variable | Se usa cuando | Ejemplo |
|---|---|---|
| `ECUAFACT_API_KEY` | No pasas `apiKey` (o pasas `null`/`false`) | `ek_live_...` |
| `ECUAFACT_BASE_URL` | No pasas `baseAddress` (o pasas `null`/`false`) | `https://staging-api.mynexusapi.com/` |

El SDK no distingue ambientes: el ambiente lo define la URL. La URL debe ser absoluta y usar
`https`, sin usuario, consulta ni fragmento, y **no** debe terminar en `/v1`: el SDK agrega `/v1` a
cada ruta. Una URL con `http`, una URL invalida o una
API Key con espacios al inicio o al final o con caracteres de control lanza
`EcuafactConfigurationException`.

## Importes exactos

Importes, cantidades, precios y tarifas de la solicitud aceptan `float` o `string` decimal. El string
(`'11.50'`) viaja como numero JSON literal (`11.50`), sin pasar por float. Un float se envia con 15
digitos significativos: `0.1 + 0.2` viaja como `0.3`. Un string que no sea decimal (`'1e3'`) lanza
`EcuafactSdkException` antes de enviar.

`Hydrator::hydrate()` convierte cada valor al tipo declarado y, si no puede, lanza
`EcuafactSdkException` (nunca un `TypeError`).

## Idempotencia

`idempotencyKey` es opcional: si no se envia, el SDK la genera, la reutiliza en los reintentos y la
devuelve en `resultado->idempotencyKey` (y en el error, como `->idempotencyKey`). Formato: solo letras,
digitos y `- _ . : /`, maximo 128 caracteres; otra clave lanza `EcuafactSdkException` sin llamar al API.
Al reintentar, **reutiliza la clave devuelta**. La correlacion queda en `resultado->correlationId`
(o `->correlationId` en el error).

`getOperacion`, `consultarOperacion` y `esperarResultado` exigen un `idOperacion` UUID.

## Validar sin emitir

Las reglas de negocio de los comprobantes (campos requeridos, formatos, catalogos, topes, coherencia aritmetica) viven
solo en el API; el SDK no las revisa. `validar` siempre llama a `/validar` y `emitir` envia directo y lanza
`EcuafactValidationException` con el 422 del API.

`validar($comprobante)` y `validarEn($identificacion, $comprobante)` aplican las reglas de la emision sin
emitir, sin consumir cupo y sin `Idempotency-Key`. Usan la ruta del tipo y le agregan
`/validar` (por ejemplo `.../notas-credito/validar`), y envian el cuerpo del tipo tal cual. Devuelven
`ValidacionResultado` (`valido`, `codEstado`, `msgError`, `errores`, `advertencias`, `correlationId`). Con un
comprobante valido, `codEstado` y `msgError` son `null` y `errores` esta vacio; `advertencias` trae los avisos que el
API devuelve junto al resultado (por ejemplo, que ignora la firma de un XML que la trae) y no impide emitir.

```php
$validacion = $client->validarEn('0123456789001', $comprobante);
if (!$validacion->valido) {          // 422 con codEstado 3002: no lanza
    print_r($validacion->errores);
}
```

Cualquier otro error lanza la excepcion habitual (por ejemplo `EcuafactAuthException` con 403 y `codEstado` 3011).
Como no tiene efectos, se reintenta igual que un GET.

### Validar antes de cada emision (opcional)

Con `validarAntesDeEmitir: true` en `EcuafactClientOptions` (o `validarAntes: true` en `RequestOptions` para una sola
llamada), `emitir`/`emitirEn` llaman primero a `/validar`. Si `valido` es `false` o hay `errores`, lanzan
`EcuafactValidationException` (422, `codEstado` 3002) sin enviar la emision; las `advertencias` no bloquean y quedan en
`EmisionResultado::$advertencias`. Por defecto es `false` para no duplicar peticiones: sin la opcion se hace 1
peticion por emision, con ella 2.

## Reintentos

Se reintentan solo solicitudes idempotentes (GET, `validar`/`validarEn`, o POST/PUT con `Idempotency-Key`) ante errores de red
y los estados de `retryableStatusCodes` (`408, 425, 500, 502, 503, 504`).

- `429` con `codEstado` 5005 (`limite_solicitudes`): se reintenta por defecto, respetando `Retry-After` con el
  tope `maxRetryDelaySeconds`. `retryRateLimited: false` lo desactiva.
- `429` con `codEstado` 4004 (`cupo_agotado`), con otro codigo o sin cuerpo: **nunca** se reintenta.

## Opciones

| Opcion | Default | Descripcion |
|---|---|---|
| `baseAddress` | `ECUAFACT_BASE_URL` | URL base del despliegue, sin `/v1` |
| `apiKey` | `ECUAFACT_API_KEY` | Credencial `X-Api-Key`; no se muestra en `var_dump`, `print_r` ni trazas |
| `identificacion` | `null` | Contribuyente por defecto (opcional) |
| `timeout` | `30.0` | Tiempo maximo por intento, en segundos (admite fracciones) |
| `userAgent` | `Ecuafact.Sdk/1.0` | User-Agent |
| `retryTransientFailures` | `true` | `false` desactiva todos los reintentos |
| `maxAttempts` | `3` | Intentos por solicitud (minimo 1) |
| `respectRetryAfter` | `true` | Espera al menos lo que indica `Retry-After` |
| `maxRetryDelaySeconds` | `60.0` | Espera maxima entre reintentos (`0` = sin tope) |
| `retryRateLimited` | `true` | Reintenta el `429` con `codEstado` 5005; nunca el 4004 |
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
| `maxResponseBytes` | 32 MiB (100 MiB en descargas) | Tope en bytes de la respuesta; `null` o `0` lo desactiva. Al superarlo lanza `EcuafactResponseTooLargeException` (`$limite`, `$bytesLeidos`) |
| `validarAntesDeEmitir` | `false` | `true` llama a `/validar` antes de cada emision (2 peticiones); si no es valido lanza `EcuafactValidationException` sin emitir |
| `additionalInfo` | `null` | Cabecera `X-Additional-Info` en todas las solicitudes: ASCII imprimible, sin espacios al inicio/fin, maximo 256; `null`/`''` no la envia; no aparece en `var_dump` |

El transporte cURL reutiliza el mismo handle (keep-alive). Para usar Guzzle o Symfony HttpClient,
instala `psr/http-client` y `psr/http-factory` y pasa
`transport: new Psr18Transport($cliente, $requestFactory, $streamFactory)`; el timeout, el proxy y TLS
los configura tu cliente. `Psr18Transport` distingue timeout de error de conexion por el tipo de
excepcion (errno 28 de cURL en `getHandlerContext()` de Guzzle, `TimeoutExceptionInterface` de
Symfony) y usa el texto del mensaje solo como respaldo.

Cada modelo de respuesta guarda en `extra` (`array`) los campos que el API devuelve y el modelo no
declara; `extra` no se envia al API.

## Opciones por llamada

Todos los metodos que llaman al API (tambien `para()` y `esperarResultado`) aceptan
como ultimo argumento `?RequestOptions $opciones`. Solo afectan a esa llamada.

```php
use Ecuafact\Sdk\RequestOptions;

$opciones = new RequestOptions(
    timeout: 20.0,            // por intento; reemplaza al timeout del cliente
    totalTimeout: 45.0,       // tope de la llamada, incluidos reintentos
    correlationId: 'PED-2026-0457', // cabecera X-Correlation-Id: 1-64 de A-Z a-z 0-9 . _ -
    reintentos: true,         // false: sin reintentos en esta llamada
    additionalInfo: 'erp=MiERP/4.2; caja=3', // cabecera X-Additional-Info; reemplaza a la del cliente
    validarAntes: true,      // solo emitir: llama a /validar antes; null usa validarAntesDeEmitir del cliente
);
$resultado = $client->emitirEn('0123456789001', $comprobante, 'FACTURA-2026-0001', $opciones);
echo $resultado->correlationId; // la correlacion que devolvio el API
```

- Un `correlationId` invalido, un `additionalInfo` invalido o un tiempo `<= 0` lanza
  `EcuafactConfigurationException`.
- La correlacion queda en `EmisionResultado::$correlationId`, en `EcuafactApiException::$correlationId`
  y en `EcuafactConnectionException::$correlationId` / `EcuafactTimeoutException::$correlationId`.
- `timeout` por llamada se aplica con el transporte cURL (o uno que implemente
  `TimeoutOverrideTransportInterface`); con un transporte PSR-18 lo controla tu cliente HTTP.
- PHP no tiene cancelacion cooperativa: acota la llamada con `timeout` o `totalTimeout`.

## Constructores de comprobantes

Opcionales. Solo ensamblan la solicitud de su tipo (`FacturaRequest`, `NotaCreditoRequest`...) con los valores que les das (no calculan totales ni
impuestos, no redondean y no completan valores por defecto); puedes seguir armandolo a mano.

```php
use Ecuafact\Sdk\Builders\ComprobanteBuilder;
use Ecuafact\Sdk\Builders\Tarifa;

$builder = ComprobanteBuilder::factura('0123456789001', '002', '001')
    ->referencias('MiERP', 'FACTURA-2026-0001')
    ->fechaEmision('07/10/2026')
    ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS')
    ->moneda('DOLAR')
    ->linea('SERV-001', 'CONSULTORIA', 3, '12.50', '2.50', Tarifa::iva('4', 15))
    ->totalSinImpuestos('35.00')   // los importes los calculas tu
    ->importeTotal('40.25')
    ->pago('01', '40.25');

$resultado = $client->emitirEn('0123456789001', $builder->construir(), 'FACTURA-2026-0001');
```

- Tipos: `factura`, `liquidacionCompra`, `notaCredito`, `notaDebito`, `guiaRemision`, `retencion`.
- Lo que asignas se envia tal cual (`importeTotal()`, `detalle(DetalleCompraVenta)`, `configurar(fn)`...) y lo que no
  asignas no se envia: sin totales, impuestos, `moneda`, `propina`, `pagoLocExt` ni `total` de pago por defecto.
- Los importes (`int`, `float` o texto decimal como `'10.00'`) conservan su precision sin perdida.
- Los constructores no calculan ni validan reglas de negocio: usa `validar` o emite y lee el 422 del API, que
  es quien calcula y decide.

## Estados tipados

La operacion y el webhook llevan `estado` con uno de estos valores
(`EstadoOperacion`):

| estado | Caso | Final |
|---|---|---|
| `recibido` (`Recibido`) | El comprobante paso las validaciones y se acepto; aun no hay resultado | No |
| `autorizado` (`Autorizado`) | Autorizado por el SRI | Si |
| `rechazado` (`Rechazado`) | Rechazado por el contenido del comprobante | Si |
| `rechazo_configuracion` (`RechazoConfiguracion`) | Falta o esta mal una configuracion del emisor | Si |
| `error` (`Error`) | Un fallo impidio definitivamente la autorizacion | Si |
| `cancelado` (`Cancelado`) | Envio cancelado por la plataforma | Si |

Junto al `estado` llega `codEstado`, un numero de 4 digitos cuyo primer digito es el `estado`: 1001 recibido
sin clave de acceso, 1002 recibido con `claveAcceso`, 2001 autorizado, 3xxx rechazado (3001 `rechazo_sri`),
4xxx rechazo de configuracion (4002 `firma_no_valida`, 4004 `cupo_agotado`), 5xxx error y 6001 cancelado. En
los estados finales distintos de `autorizado`, `msgError` describe el codigo y `errores` trae el detalle (por
ejemplo el mensaje del SRI); sin error, `msgError` es `null` y `errores` esta vacio. `Contracts\CodEstado` trae
una constante por codigo, `CodEstado::estado($codigo)` devuelve el `EstadoOperacion` de un codigo (tambien de
uno nuevo) y `CodEstado::nombre($codigo)` su nombre en el catalogo.

La emision (`Admission`) y la operacion (`Operation`) tienen los mismos campos: `estado`, `codEstado`,
`msgError`, `errores`, `idOperacion`, `urlEstado` (URL absoluta de la operacion), `ambiente`, `tipoDocumento`,
`numeroDocumento`, `referenciaExterna`, `claveAcceso`, `fhRecibido` y `fhAutorizacion`.

`Admission` y `Operation` ofrecen `estadoOperacion()`, `tipoComprobante()` (`TipoComprobante`, a partir de
`tipoDocumento`), `esFinal()` y `esAutorizado()`; `EstadoOperacion` tambien tiene `esFinal()` y
`esAutorizado()`. Un valor nuevo del API se lee como `Desconocido` (no final) o `null` en `TipoComprobante`;
los campos string originales no cambian.

## Errores

| Excepcion | Cuando | Que hacer |
|---|---|---|
| `EcuafactAuthException` | 401/403 | Revisa la API Key y el contribuyente autorizado |
| `EcuafactValidationException` | 400/413/415/422/501 | Corrige los datos segun `errores` |
| `EcuafactNotFoundException` | 404 | Revisa la identificacion, la clave o el id |
| `EcuafactConflictException` | 409 | Misma `Idempotency-Key` con otro contenido, o comprobante duplicado |
| `EcuafactRateLimitException` | 429 con `codEstado` 5005 | Espera `retryAfter` segundos y repite |
| `EcuafactQuotaException` | 429 con `codEstado` 4004 | Compra cupo; no repitas |
| `EcuafactServerException` | 5xx | Repite con la misma `Idempotency-Key` si `esReintentable` |
| `EcuafactConnectionException` | Sin respuesta HTTP | Repite con la misma `Idempotency-Key` |
| `EcuafactTimeoutException` | Tiempo agotado | Consulta la operacion o repite con la misma clave |
| `EcuafactConfigurationException` | Opcion invalida | Corrige la configuracion |
| `EcuafactTipoComprobanteException` | El filtro `tipoDocumento` de `listarOperaciones` no es un tipo valido | Usa `01`, `03`, `04`, `05`, `06`, `07` o su nombre |
| `EcuafactResponseTooLargeException` | Respuesta mayor que `maxResponseBytes` | Sube o desactiva el tope; no se reintenta |
| `EcuafactWebhookException` | Firma de webhook invalida | Responde 400 y no proceses el evento |

Todo error del API tiene la misma forma: `{ "estado": "rechazado", "codEstado": 3002, "msgError": "...",
"errores": [...] }`. Las de API heredan de `EcuafactApiException` (`codEstado` como int, `estado`, `msgError`,
`errores`, `status` con el codigo HTTP, `correlationId`, `idempotencyKey`, `retryAfter`, `esReintentable`;
`estadoHttp` es el mismo valor que `status`); todas heredan de `EcuafactSdkException`. Si el cuerpo no era
JSON, `codEstado` vale 0. Compara `codEstado` con las constantes de `Contracts\CodEstado`:

```php
use Ecuafact\Sdk\Contracts\CodEstado;
use Ecuafact\Sdk\EcuafactApiException;

try {
    $client->emitir($comprobante);
} catch (EcuafactApiException $e) {
    if ($e->codEstado === CodEstado::COMPROBANTE_INVALIDO) {
        print_r($e->errores);                // detalle por campo
    }
    error_log($e->status . ' ' . $e->codEstado . ' ' . $e->msgError . ' ' . $e->correlationId);
}
```

`Contracts\EstadosPublicos` trae los valores de texto de `estado`.

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
switch ($evento->eventType) {
    case TipoEventoWebhook::DocumentoAutorizado: // estado autorizado, codEstado 2001
        // $evento->idOperacion, $evento->claveAcceso, $evento->tipoDocumento, $evento->fhAutorizacion
        break;
    case TipoEventoWebhook::DocumentoRechazado: // rechazado con codEstado 3001
        // $evento->errores trae el mensaje del SRI
        break;
    case TipoEventoWebhook::DocumentoFallido: // rechazado (3002), rechazo_configuracion (4xxx) o error (5xxx)
        // $evento->estado, $evento->codEstado, $evento->msgError, $evento->errores; siempre final
        break;
    case TipoEventoWebhook::OperacionRequiereAtencion: // aviso no final (sigue recibido) o evento de prueba
        break;
}
```

`document.authorized` llega con `estado` `autorizado`; `document.rejected`, solo con `rechazado` y `codEstado`
3001 (`rechazo_sri`); `document.failed`, con `rechazado` y 3002 (`comprobante_invalido`), con
`rechazo_configuracion` o con `error`, y siempre es final. El cuerpo trae `codEstado`, `errores`, `msgError`
(solo si hay error) `fhAutorizacion` (si existe) y `ruc`. `operation.requires_attention` es un aviso no final: el documento sigue `recibido`
y el resultado llega despues con otro evento. Un documento `cancelado` no genera evento. `$evento->cuerpo` trae
el cuerpo completo decodificado.

`WebhookSignature::verify($secretos, $cabecera, $cuerpo)` devuelve `false` (nunca lanza) ante una
cabecera malformada, vencida o con firma distinta.

### Standard Webhooks y cualquiera de las dos firmas

Cada entrega trae tambien las cabeceras de [Standard Webhooks](https://www.standardwebhooks.com)
(`webhook-id`, `webhook-timestamp` y `webhook-signature`), firmadas con el mismo secreto decodificado de
Base64. Basta con verificar una de las dos. `construirEventoDesdeCabeceras` recibe las cabeceras de la
peticion, verifica `Ecuafact-Signature` si viene y, si no viene o no es valida, Standard Webhooks:

```php
try {
    // getallheaders(), $_SERVER o $request->getHeaders() de PSR-7; los nombres no distinguen mayusculas.
    $evento = WebhookSignature::construirEventoDesdeCabeceras([$secretoActual, $secretoAnterior],
        getallheaders(), $cuerpo);
} catch (EcuafactWebhookException) {
    http_response_code(400);
    return;
}
```

`WebhookSignature::verificarCabeceras($secretos, $cabeceras, $cuerpo)` hace lo mismo y devuelve `bool`, y
`WebhookSignature::verificarStandardWebhooks($secretos, $webhookId, $webhookTimestamp, $webhookSignature, $cuerpo)`
verifica solo el estandar. Aceptan el secreto con o sin el prefijo `whsec_`; un secreto que no es Base64 da
`false`. La tolerancia por defecto tambien es de 300 segundos.

## Documentacion

- [Guia del SDK PHP](https://docsapi.ecuafact.com/v1/guias/sdk-php)

## Licencia

MIT.

