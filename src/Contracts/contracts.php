<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

// Contratos del API publico v1.
// Se cargan via composer "autoload.files" para mantenerlos en un solo archivo.

// ---------------------------------------------------------------------------
// Solicitud
//
// Importes, cantidades, precios y tarifas aceptan float o string decimal
// ('11.50'). El string se envia como numero JSON literal, sin pasar por float:
// es la forma exacta. Un float se envia con 15 digitos significativos, de modo
// que 0.1 + 0.2 viaja como 0.3.
// ---------------------------------------------------------------------------

/**
 * Un tipo de comprobante emitible. Cada tipo tiene su propia clase de solicitud (como tiene su propio endpoint) y
 * el cliente elige la ruta por ella (ver {@see TipoComprobante}). Lo implementan FacturaRequest,
 * LiquidacionCompraRequest, NotaCreditoRequest, NotaDebitoRequest, GuiaRemisionRequest y RetencionRequest.
 */
interface SolicitudComprobante
{
    /** Tipo de comprobante de la solicitud; el SDK lo usa para elegir la ruta y no viaja en el cuerpo. */
    public function tipoComprobante(): TipoComprobante;
}

class CampoAdicional
{
    /** Obligatorio. */
    public ?string $nombre = null;
    /** Obligatorio. */
    public ?string $valor = null;
}

class Impuesto
{
    /** Obligatorio. */
    public ?string $codigo = null;
    /** Obligatorio. */
    public ?string $codigoPorcentaje = null;
    public float|string|null $tarifa = null;
    /** Obligatorio. */
    public float|string|null $baseImponible = null;
    /** Obligatorio. */
    public float|string|null $valor = null;
}

class Pago
{
    /** Obligatorio. */
    public ?string $formaPago = null;
    /** Obligatorio. */
    public float|string|null $total = null;
    public float|string|null $plazo = null;
    public ?string $unidadTiempo = null;
}

class InfoTributaria
{
    /** Obligatorio. */
    public ?string $ruc = null;
    /** Obligatorio. */
    public ?string $estab = null;
    /** Obligatorio. */
    public ?string $ptoEmi = null;
    public ?string $secuencial = null;
}

/** Linea de factura y de liquidacion de compra (`DetalleCompraVentaDto`). */
class DetalleCompraVenta
{
    /** Obligatorio. */
    public ?string $codigoPrincipal = null;
    public ?string $codigoAuxiliar = null;
    /** Obligatorio. */
    public ?string $descripcion = null;
    /** Obligatorio. */
    public float|string|null $cantidad = null;
    /** Obligatorio. */
    public float|string|null $precioUnitario = null;
    /** Obligatorio. */
    public float|string|null $descuento = null;
    /** Obligatorio. */
    public float|string|null $precioTotalSinImpuesto = null;
    /** @var Impuesto[] Obligatorio. */
    public ?array $impuestos = null;
    /** @var CampoAdicional[] */
    public ?array $detallesAdicionales = null;
}

/** Linea de nota de credito (`DetalleNotaCreditoDto`). */
class DetalleNotaCredito
{
    /** Obligatorio. */
    public ?string $codigoInterno = null;
    public ?string $codigoAdicional = null;
    /** Obligatorio. */
    public ?string $descripcion = null;
    /** Obligatorio. */
    public float|string|null $cantidad = null;
    /** Obligatorio. */
    public float|string|null $precioUnitario = null;
    /** Obligatorio. */
    public float|string|null $descuento = null;
    /** Obligatorio. */
    public float|string|null $precioTotalSinImpuesto = null;
    /** @var Impuesto[] Obligatorio. */
    public ?array $impuestos = null;
    /** @var CampoAdicional[] */
    public ?array $detallesAdicionales = null;
}

/** Bien trasladado en una guia de remision (`DetalleGuiaDto`). */
class DetalleGuia
{
    /** Obligatorio. */
    public ?string $codigoInterno = null;
    public ?string $codigoAdicional = null;
    /** Obligatorio. */
    public ?string $descripcion = null;
    /** Obligatorio. */
    public float|string|null $cantidad = null;
    /** @var CampoAdicional[] */
    public ?array $detallesAdicionales = null;
}

/** Motivo de una nota de debito (`MotivoDto`). */
class Motivo
{
    /** Obligatorio. */
    public ?string $razon = null;
    /** Obligatorio. */
    public float|string|null $valor = null;
}

/** Destinatario de una guia de remision (`DestinatarioDto`). */
class Destinatario
{
    /** Obligatorio. */
    public ?string $identificacionDestinatario = null;
    /** Obligatorio. */
    public ?string $razonSocialDestinatario = null;
    /** Obligatorio. */
    public ?string $dirDestinatario = null;
    /** Obligatorio. */
    public ?string $motivoTraslado = null;
    public ?string $docAduaneroUnico = null;
    public ?string $codEstabDestino = null;
    public ?string $ruta = null;
    /** @var DetalleGuia[] Obligatorio. */
    public ?array $detalles = null;
}

class ImpuestoDocSustento
{
    /** Obligatorio. */
    public ?string $codImpuestoDocSustento = null;
    /** Obligatorio. */
    public ?string $codigoPorcentaje = null;
    /** Obligatorio. */
    public float|string|null $baseImponible = null;
    /** Obligatorio. */
    public float|string|null $tarifa = null;
    /** Obligatorio. */
    public float|string|null $valorImpuesto = null;
}

class RetencionLinea
{
    /** Obligatorio. */
    public ?string $codigo = null;
    /** Obligatorio. */
    public ?string $codigoRetencion = null;
    /** Obligatorio. */
    public float|string|null $baseImponible = null;
    /** Obligatorio. */
    public float|string|null $porcentajeRetener = null;
    /** Obligatorio. */
    public float|string|null $valorRetenido = null;
}

/** Documento de sustento de una retencion (`DocsSustentoDto`). */
class DocsSustento
{
    /** Obligatorio. */
    public ?string $codSustento = null;
    /** Obligatorio. */
    public ?string $codDocSustento = null;
    /** Obligatorio. */
    public ?string $numDocSustento = null;
    /** Obligatorio. */
    public ?string $fechaEmisionDocSustento = null;
    /** Obligatorio. */
    public ?string $fechaRegistroContable = null;
    public ?string $numAutDocSustento = null;
    /** Obligatorio. */
    public ?string $pagoLocExt = null;
    /** Obligatorio. */
    public float|string|null $totalSinImpuestos = null;
    /** Obligatorio. */
    public float|string|null $importeTotal = null;
    /** @var ImpuestoDocSustento[] Obligatorio. */
    public ?array $impuestosDocSustento = null;
    /** @var RetencionLinea[] Obligatorio. */
    public ?array $retenciones = null;
    /** @var Pago[] Obligatorio. */
    public ?array $pagos = null;
}

/** Cabecera de la factura (`InfoFacturaDto`), con los datos de exportacion. */
class InfoFactura
{
    /** Obligatorio. */
    public ?string $fechaEmision = null;
    /** Obligatorio. */
    public ?string $tipoIdentificacionComprador = null;
    /** Obligatorio. */
    public ?string $razonSocialComprador = null;
    /** Obligatorio. */
    public ?string $identificacionComprador = null;
    public ?string $direccionComprador = null;
    /** Obligatorio. */
    public float|string|null $totalSinImpuestos = null;
    /** Obligatorio. */
    public ?string $moneda = null;
    /** Obligatorio. */
    public float|string|null $totalDescuento = null;
    /** @var Impuesto[] Obligatorio. */
    public ?array $totalConImpuestos = null;
    /** Obligatorio. */
    public float|string|null $importeTotal = null;
    /** Obligatorio. */
    public float|string|null $propina = null;
    /** @var Pago[] Obligatorio. */
    public ?array $pagos = null;
    public ?string $guiaRemision = null;
    public ?string $placa = null;
    public ?string $comercioExterior = null;
    public ?string $incoTermFactura = null;
    public ?string $lugarIncoTerm = null;
    public ?string $paisOrigen = null;
    public ?string $puertoEmbarque = null;
    public ?string $puertoDestino = null;
    public ?string $paisDestino = null;
    public ?string $paisAdquisicion = null;
    public ?string $incoTermTotalSinImpuestos = null;
    public ?string $dirEstablecimiento = null;
    public ?string $telefono = null;
    public ?string $correo = null;
}

/** Cabecera de la liquidacion de compra (`InfoLiquidacionCompraDto`). */
class InfoLiquidacionCompra
{
    /** Obligatorio. */
    public ?string $fechaEmision = null;
    /** Obligatorio. */
    public ?string $tipoIdentificacionProveedor = null;
    /** Obligatorio. */
    public ?string $razonSocialProveedor = null;
    /** Obligatorio. */
    public ?string $identificacionProveedor = null;
    public ?string $direccionProveedor = null;
    /** Obligatorio. */
    public float|string|null $totalSinImpuestos = null;
    /** Obligatorio. */
    public ?string $moneda = null;
    /** Obligatorio. */
    public float|string|null $totalDescuento = null;
    /** @var Impuesto[] Obligatorio. */
    public ?array $totalConImpuestos = null;
    /** Obligatorio. */
    public float|string|null $importeTotal = null;
    /** @var Pago[] Obligatorio. */
    public ?array $pagos = null;
    public ?string $dirEstablecimiento = null;
    public ?string $telefono = null;
    public ?string $correo = null;
}

/** Cabecera de la nota de credito (`InfoNotaCreditoDto`). */
class InfoNotaCredito
{
    /** Obligatorio. */
    public ?string $fechaEmision = null;
    /** Obligatorio. */
    public ?string $tipoIdentificacionComprador = null;
    /** Obligatorio. */
    public ?string $razonSocialComprador = null;
    /** Obligatorio. */
    public ?string $identificacionComprador = null;
    public ?string $direccionComprador = null;
    /** Obligatorio. */
    public float|string|null $totalSinImpuestos = null;
    /** Obligatorio. */
    public ?string $moneda = null;
    /** @var Impuesto[] Obligatorio. */
    public ?array $totalConImpuestos = null;
    /** Obligatorio. */
    public float|string|null $valorModificacion = null;
    /** Obligatorio. */
    public ?string $codDocModificado = null;
    /** Obligatorio. */
    public ?string $numDocModificado = null;
    /** Obligatorio. */
    public ?string $fechaEmisionDocSustento = null;
    public float|string|null $totalDocumentoSustento = null;
    /** Obligatorio. */
    public ?string $motivo = null;
    public ?string $dirEstablecimiento = null;
    public ?string $telefono = null;
    public ?string $correo = null;
}

/** Cabecera de la nota de debito (`InfoNotaDebitoDto`). */
class InfoNotaDebito
{
    /** Obligatorio. */
    public ?string $fechaEmision = null;
    /** Obligatorio. */
    public ?string $tipoIdentificacionComprador = null;
    /** Obligatorio. */
    public ?string $razonSocialComprador = null;
    /** Obligatorio. */
    public ?string $identificacionComprador = null;
    public ?string $direccionComprador = null;
    /** Obligatorio. */
    public float|string|null $totalSinImpuestos = null;
    public ?string $moneda = null;
    /** Obligatorio. */
    public ?string $codDocModificado = null;
    /** Obligatorio. */
    public ?string $numDocModificado = null;
    /** Obligatorio. */
    public ?string $fechaEmisionDocSustento = null;
    /** Obligatorio. */
    public float|string|null $valorTotal = null;
    /** @var Impuesto[] Obligatorio. */
    public ?array $impuestos = null;
    /** @var Pago[] Obligatorio. */
    public ?array $pagos = null;
    public ?string $dirEstablecimiento = null;
    public ?string $telefono = null;
    public ?string $correo = null;
}

/** Cabecera de la guia de remision (`InfoGuiaRemisionDto`). */
class InfoGuiaRemision
{
    /** Obligatorio. */
    public ?string $dirPartida = null;
    /** Obligatorio. */
    public ?string $razonSocialTransportista = null;
    /** Obligatorio. */
    public ?string $tipoIdentificacionTransportista = null;
    /** Obligatorio. */
    public ?string $rucTransportista = null;
    /** Obligatorio. */
    public ?string $fechaIniTransporte = null;
    /** Obligatorio. */
    public ?string $fechaFinTransporte = null;
    /** Obligatorio. */
    public ?string $placa = null;
    public ?string $dirEstablecimiento = null;
    public ?string $telefono = null;
    public ?string $correo = null;
}

/** Cabecera de la retencion (`InfoComprobanteRetencionDto`). */
class InfoComprobanteRetencion
{
    /** Obligatorio. */
    public ?string $fechaEmision = null;
    /** Obligatorio. */
    public ?string $tipoIdentificacionSujetoRetenido = null;
    /** Obligatorio. */
    public ?string $razonSocialSujetoRetenido = null;
    /** Obligatorio. */
    public ?string $identificacionSujetoRetenido = null;
    /** Obligatorio. */
    public ?string $periodoFiscal = null;
    /** Obligatorio. */
    public ?string $parteRel = null;
    public ?string $tipoSujetoRetenido = null;
    public ?string $dirEstablecimiento = null;
    public ?string $telefono = null;
    public ?string $correo = null;
}

/** Factura (`FacturaRequestDto`), tipo 01. */
class FacturaRequest implements SolicitudComprobante
{
    /** Obligatorio. */
    public ?string $origenReferencia = null;
    /** Obligatorio. */
    public ?string $referenciaExterna = null;
    /** Obligatorio. */
    public ?InfoTributaria $infoTributaria = null;
    /** Obligatorio. */
    public ?InfoFactura $info = null;
    /** @var DetalleCompraVenta[] Obligatorio. */
    public ?array $detalles = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;

    public function tipoComprobante(): TipoComprobante
    {
        return TipoComprobante::Factura;
    }
}

/** Liquidacion de compra (`LiquidacionCompraRequestDto`), tipo 03. */
class LiquidacionCompraRequest implements SolicitudComprobante
{
    /** Obligatorio. */
    public ?string $origenReferencia = null;
    /** Obligatorio. */
    public ?string $referenciaExterna = null;
    /** Obligatorio. */
    public ?InfoTributaria $infoTributaria = null;
    /** Obligatorio. */
    public ?InfoLiquidacionCompra $info = null;
    /** @var DetalleCompraVenta[] Obligatorio. */
    public ?array $detalles = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;

    public function tipoComprobante(): TipoComprobante
    {
        return TipoComprobante::LiquidacionCompra;
    }
}

/** Nota de credito (`NotaCreditoRequestDto`), tipo 04. */
class NotaCreditoRequest implements SolicitudComprobante
{
    /** Obligatorio. */
    public ?string $origenReferencia = null;
    /** Obligatorio. */
    public ?string $referenciaExterna = null;
    /** Obligatorio. */
    public ?InfoTributaria $infoTributaria = null;
    /** Obligatorio. */
    public ?InfoNotaCredito $info = null;
    /** @var DetalleNotaCredito[] Obligatorio. */
    public ?array $detalles = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;

    public function tipoComprobante(): TipoComprobante
    {
        return TipoComprobante::NotaCredito;
    }
}

/** Nota de debito (`NotaDebitoRequestDto`), tipo 05. */
class NotaDebitoRequest implements SolicitudComprobante
{
    /** Obligatorio. */
    public ?string $origenReferencia = null;
    /** Obligatorio. */
    public ?string $referenciaExterna = null;
    /** Obligatorio. */
    public ?InfoTributaria $infoTributaria = null;
    /** Obligatorio. */
    public ?InfoNotaDebito $info = null;
    /** @var Motivo[] Obligatorio. */
    public ?array $motivos = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;

    public function tipoComprobante(): TipoComprobante
    {
        return TipoComprobante::NotaDebito;
    }
}

/** Guia de remision (`GuiaRemisionRequestDto`), tipo 06. */
class GuiaRemisionRequest implements SolicitudComprobante
{
    /** Obligatorio. */
    public ?string $origenReferencia = null;
    /** Obligatorio. */
    public ?string $referenciaExterna = null;
    /** Obligatorio. */
    public ?InfoTributaria $infoTributaria = null;
    /** Obligatorio. */
    public ?InfoGuiaRemision $info = null;
    /** @var Destinatario[] Obligatorio. */
    public ?array $destinatarios = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;

    public function tipoComprobante(): TipoComprobante
    {
        return TipoComprobante::GuiaRemision;
    }
}

/** Comprobante de retencion (`ComprobanteRetencionRequestDto`), tipo 07. */
class RetencionRequest implements SolicitudComprobante
{
    /** Obligatorio. */
    public ?string $origenReferencia = null;
    /** Obligatorio. */
    public ?string $referenciaExterna = null;
    /** Obligatorio. */
    public ?InfoTributaria $infoTributaria = null;
    /** Obligatorio. */
    public ?InfoComprobanteRetencion $info = null;
    /** @var DocsSustento[] Obligatorio. */
    public ?array $docsSustento = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;

    public function tipoComprobante(): TipoComprobante
    {
        return TipoComprobante::Retencion;
    }
}

// ---------------------------------------------------------------------------
// Respuestas
// ---------------------------------------------------------------------------

/**
 * Forma unica de error de todo el API: `{ estado, codEstado, msgError, errores }`. Los rechazos sincronicos de
 * la emision traen ademas los campos de {@see Admission}, que el SDK conserva en `extra`.
 */
class ApiError
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** `rechazado`, `rechazo_configuracion` o `error`. */
    public ?string $estado = null;
    /** Codigo numerico de 4 digitos (ver {@see CodEstado}). */
    public ?int $codEstado = null;
    /** Descripcion general y fija del codigo. */
    public ?string $msgError = null;
    /** @var string[] Detalle especifico (ruta del campo, mensaje del SRI...); vacio si no hay detalle. */
    public ?array $errores = null;
}

/**
 * Respuesta de la emision (202) y, con la misma forma, de la operacion ({@see Operation}).
 *
 * `estado` y `codEstado` describen el resultado actual: 1001 recibido sin clave, 1002 recibido con
 * `claveAcceso`, 2001 autorizado (envio directo) o un estado final 3xxx/4xxx/5xxx/6xxx. Consulta
 * `idOperacion` (o `urlEstado`) hasta un estado final.
 */
class Admission
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** `recibido`, `autorizado`, `rechazado`, `rechazo_configuracion`, `error` o `cancelado`. */
    public ?string $estado = null;
    /** Codigo numerico de 4 digitos; el primer digito es el `estado` (ver {@see CodEstado}). */
    public ?int $codEstado = null;
    /** Descripcion general del codigo; null cuando no hay error (el API la omite). */
    public ?string $msgError = null;
    /** @var string[] Detalle especifico del resultado; vacio si no hay detalle. */
    public ?array $errores = null;
    /** Identificador (GUID) de la operacion. */
    public ?string $idOperacion = null;
    /** URL absoluta de la operacion: `https://<host del ambiente>/v1/operaciones/{idOperacion}`. */
    public ?string $urlEstado = null;
    /** Ambiente fiscal: 1 pruebas, 2 produccion. */
    public ?int $ambiente = null;
    /** `01`, `03`, `04`, `05`, `06` o `07`. */
    public ?string $tipoDocumento = null;
    /** `estab-ptoEmi-secuencial`, por ejemplo `001-001-000000123`. */
    public ?string $numeroDocumento = null;
    /** Referencia que envio el cliente. */
    public ?string $referenciaExterna = null;
    /** Clave de acceso de 49 digitos; null con 1001. */
    public ?string $claveAcceso = null;
    /** Momento en que la API acepto la emision (fecha-hora con zona). */
    public ?string $fhRecibido = null;
    /** Fecha-hora de autorizacion con zona, cuando existe. */
    public ?string $fhAutorizacion = null;

    /** `estado` tipado; un valor nuevo del API se lee como EstadoOperacion::Desconocido. */
    public function estadoOperacion(): EstadoOperacion
    {
        return EstadoOperacion::desdeValor($this->estado);
    }

    /** `tipoDocumento` tipado; null si el codigo no se reconoce. */
    public function tipoComprobante(): ?TipoComprobante
    {
        return TipoComprobante::desdeValor($this->tipoDocumento);
    }

    /**
     * True cuando el resultado ya no cambiara: `estado` autorizado, rechazado, rechazo_configuracion,
     * error o cancelado. `recibido` y un valor desconocido no son finales.
     */
    public function esFinal(): bool
    {
        return $this->estadoOperacion()->esFinal();
    }

    /** True cuando `estado` es autorizado. */
    public function esAutorizado(): bool
    {
        return $this->estadoOperacion()->esAutorizado();
    }
}

/** Estado de una operacion (`GET v1/operaciones/{idOperacion}`): misma forma que {@see Admission}. */
class Operation extends Admission
{
}

/** Fila del listado de comprobantes (`GET v1/operaciones`). */
class Comprobante
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $claveAcceso = null;
    /** `estab-ptoEmi-secuencial`, por ejemplo `001-001-000000123`. */
    public ?string $numeroDocumento = null;
    /** `01`, `03`, `04`, `05`, `06` o `07`. */
    public ?string $tipoDocumento = null;
    public ?string $fechaEmision = null;
    public ?string $identificacionContraparte = null;
    public ?string $razonSocialContraparte = null;
    public ?float $total = null;
    /** `recibido`, `autorizado`, `rechazado`, `rechazo_configuracion`, `error` o `cancelado`. */
    public ?string $estado = null;
    /** Codigo numerico de 4 digitos (ver {@see CodEstado}). */
    public ?int $codEstado = null;
    /** Descripcion general del codigo; null cuando no hay error (el API la omite). */
    public ?string $msgError = null;
    /** @var string[] Detalle especifico; vacio si no hay detalle. */
    public ?array $errores = null;
    public ?string $fhAutorizacion = null;

    /** `estado` tipado; un valor nuevo del API se lee como EstadoOperacion::Desconocido. */
    public function estadoOperacion(): EstadoOperacion
    {
        return EstadoOperacion::desdeValor($this->estado);
    }

    /** `tipoDocumento` tipado; null si el codigo no se reconoce. */
    public function tipoComprobante(): ?TipoComprobante
    {
        return TipoComprobante::desdeValor($this->tipoDocumento);
    }
}

/** Pagina del listado de comprobantes (`GET v1/operaciones`). */
class PaginaComprobantes
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** @var Comprobante[] */
    public ?array $comprobantes = null;
    public ?int $pagina = null;
    public ?int $tamanoPagina = null;
    /** True si existe una pagina siguiente. */
    public ?bool $hayMas = null;
}

class QuotaBucket
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?int $limiteDocumentos = null;
    public ?int $reservados = null;
    public ?int $consumidos = null;
    public ?int $disponibles = null;
    public ?string $vigenteHasta = null;
    public ?int $limiteExtra = null;
    public ?int $reservadosExtra = null;
    public ?int $consumidosExtra = null;
    public ?int $disponiblesExtra = null;
}

class EstadoServicio
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** Estado general: `operativo`, `degradado`, `caido` o `sin_datos`. */
    public ?string $estado = null;
    /** Momento de la ultima medicion (ISO 8601); `null` si aun no hay mediciones. */
    public ?string $medidoEn = null;
    /** @var ComponenteEstado[] */
    public ?array $componentes = null;
    /** Porcentaje de minutos sin caida en los ultimos 90 dias; `null` si aun no hay muestras. */
    public float|string|null $disponibilidad90Dias = null;
    /** @var IncidentePublico[] */
    public ?array $incidentes = null;
}

class ComponenteEstado
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $nombre = null;
    /** `operativo`, `degradado`, `caido` o `sin_datos`. */
    public ?string $estado = null;
}

class IncidentePublico
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** Inicio del incidente (ISO 8601). */
    public ?string $inicio = null;
    /** Fin del incidente; `null` si sigue abierto. */
    public ?string $fin = null;
    /** `degradado` o `caido`. */
    public ?string $impacto = null;
    public ?string $titulo = null;
    /** Que significa para tus integraciones; `null` si no hay. */
    public ?string $detalle = null;
}

class Catalogos
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** @var CatalogoResumen[] */
    public ?array $catalogos = null;
}

class CatalogoResumen
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** Clave del catalogo; es el valor que va en `obtenerCatalogo`. */
    public ?string $nombre = null;
    public ?string $descripcion = null;
}

class CatalogoDetalle
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $nombre = null;
    public ?string $descripcion = null;
    /** Tabla o anexo de la ficha tecnica del SRI de donde proviene. */
    public ?string $fuente = null;
    /** @var CatalogoItem[] */
    public ?array $items = null;
}

class CatalogoItem
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    /** Codigo tal como va en el comprobante. */
    public ?string $codigo = null;
    public ?string $descripcion = null;
    /** Valor numerico asociado (por ejemplo la tarifa en porcentaje); `null` si el catalogo no lo tiene. */
    public float|string|null $valor = null;
    /** Primer dia de vigencia (`yyyy-MM-dd`); `null` si no esta acotada por el inicio. */
    public ?string $vigenteDesde = null;
    /** Ultimo dia de vigencia (`yyyy-MM-dd`); `null` si sigue vigente. */
    public ?string $vigenteHasta = null;
    /** @var array<string, mixed>|null Atributos propios del catalogo; `null` si no hay. */
    public ?array $atributos = null;
}

class Contribuyente
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $identificacion = null;
    public ?bool $puedeEmitir = null;
    public ?bool $puedeRecibir = null;
    /** @var string[] */
    public ?array $tiposComprobante = null;
    /** `true` si el contribuyente usa marca blanca. Lo configura Ecuafact; no se envia en ninguna solicitud. */
    public ?bool $marcaBlanca = null;
}

class Contexto
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $idCliente = null;
    public ?string $nombre = null;
    /** @var Contribuyente[] */
    public ?array $contribuyentes = null;
}

// ---------------------------------------------------------------------------
// Consultas externas del SRI
// ---------------------------------------------------------------------------

class RepresentanteLegalConsulta
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $identificacion = null;
    public ?string $nombre = null;
}

class EstablecimientoConsulta
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $numero = null;
    public ?string $nombreComercial = null;
    public ?string $tipo = null;
    public ?string $direccionCompleta = null;
    public ?string $estado = null;
    public ?bool $esMatriz = null;
}

class EstadoTributario
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?bool $tieneDeuda = null;
    public ?bool $tieneImpugnacion = null;
    public ?bool $tieneRemision = null;
    public ?string $resumenDeuda = null;
    public ?string $resumenImpugnacion = null;
    public ?string $resumenRemision = null;
    public ?string $consultadoEn = null;
}

class ContribuyenteConsulta
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $identificacion = null;
    public ?string $tipoIdentificacion = null;
    public ?string $nombreCompleto = null;
    public ?string $razonSocial = null;
    public ?string $nombreComercial = null;
    public ?string $clase = null;
    public ?string $estado = null;
    public ?string $regimen = null;
    public ?string $actividadEconomicaPrincipal = null;
    public ?bool $obligadoLlevarContabilidad = null;
    public ?bool $agenteRetencion = null;
    public ?bool $contribuyenteEspecial = null;
    public ?string $fechaInicioActividades = null;
    public ?string $fechaCese = null;
    public ?string $fechaReinicioActividades = null;
    public ?string $fechaActualizacion = null;
    /** @var RepresentanteLegalConsulta[] */
    public ?array $representantesLegales = null;
    /** @var EstablecimientoConsulta[] */
    public ?array $establecimientos = null;
    public ?EstadoTributario $estadoTributario = null;
    public ?string $fuenteOrigen = null;
    public ?string $consultadoEn = null;
}

class BusquedaContribuyente
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $identificacion = null;
    public ?string $tipoIdentificacion = null;
    public ?string $nombreCompleto = null;
    public ?string $clase = null;
    public ?string $estado = null;
    public ?string $fuenteOrigen = null;
}

class EstablecimientosContribuyente
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $ruc = null;
    public ?string $filtro = null;
    public ?int $cantidad = null;
    /** @var EstablecimientoConsulta[] */
    public ?array $establecimientos = null;
}

class ValidacionIdentificacion
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $entrada = null;
    public ?string $normalizado = null;
    public ?bool $valido = null;
    public ?string $tipo = null;
    public ?int $longitud = null;
    public ?bool $esSoloDigitos = null;
    public ?string $cedulaBaseDelRuc = null;
    public ?string $mensaje = null;
}

class ClaveAccesoDecodificada
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $entrada = null;
    public ?string $normalizada = null;
    public ?bool $valido = null;
    public ?int $longitud = null;
    public ?string $fechaEmision = null;
    public ?string $tipo = null;
    public ?string $rucEmisor = null;
    public ?string $ambiente = null;
    public ?string $establecimiento = null;
    public ?string $puntoEmision = null;
    public ?string $secuencial = null;
    public ?string $codigoNumerico = null;
    public ?string $tipoEmision = null;
    public ?string $tipoEmisionDescripcion = null;
    public ?string $digitoVerificador = null;
    public ?string $mensaje = null;
}

class ArchivoComprobante
{
    public string $contenido = '';
    public string $contentType = '';
}

class PerfilEmisor
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $identificacion = null;
    public ?string $razonSocial = null;
    public ?string $nombreComercial = null;
    public ?string $direccionMatriz = null;
    public ?string $direccionEstablecimiento = null;
    public ?string $codigoEstablecimiento = null;
    public ?string $codigoPuntoEmision = null;
    public ?string $moneda = null;
    public bool $obligadoContabilidad = false;
    public ?string $correo = null;
    public ?string $telefono = null;
    public ?string $ciudad = null;
    public ?string $provincia = null;
    public ?string $pais = null;
    public ?string $logo = null;
}

class PerfilEmisorUpdate
{
    public ?string $nombreComercial = null;
    public ?string $direccionMatriz = null;
    public ?string $correo = null;
    public ?string $telefono = null;
    public ?string $ciudad = null;
    public ?string $provincia = null;
}
