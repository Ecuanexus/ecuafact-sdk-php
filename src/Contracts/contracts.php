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

class CampoAdicional
{
    public ?string $nombre = null;
    public ?string $valor = null;
}

class Impuesto
{
    public ?string $codigo = null;
    public ?string $codigoPorcentaje = null;
    public float|string|null $tarifa = null;
    public float|string|null $baseImponible = null;
    public float|string|null $valor = null;
    public float|string|null $descuentoAdicional = null;
    public float|string|null $valorDevolucionIva = null;
}

class Pago
{
    public ?string $formaPago = null;
    public float|string|null $total = null;
    public float|string|null $plazo = null;
    public ?string $unidadTiempo = null;
}

class Detalle
{
    public ?string $codigoPrincipal = null;
    public ?string $codigoAuxiliar = null;
    public ?string $codigoInterno = null;
    public ?string $codigoAdicional = null;
    public ?string $descripcion = null;
    public ?string $unidadMedida = null;
    public float|string|null $cantidad = null;
    public float|string|null $precioUnitario = null;
    public float|string|null $precioSinSubsidio = null;
    public float|string|null $descuento = null;
    public float|string|null $precioTotalSinImpuesto = null;
    /** @var Impuesto[] */
    public ?array $impuestos = null;
    /** @var CampoAdicional[] */
    public ?array $detallesAdicionales = null;
}

class Motivo
{
    public ?string $razon = null;
    public float|string|null $valor = null;
}

class Destinatario
{
    public ?string $identificacionDestinatario = null;
    public ?string $razonSocialDestinatario = null;
    public ?string $dirDestinatario = null;
    public ?string $motivoTraslado = null;
    public ?string $docAduaneroUnico = null;
    public ?string $codEstabDestino = null;
    public ?string $ruta = null;
    /** @var Detalle[] */
    public ?array $detalles = null;
}

class ImpuestoDocSustento
{
    public ?string $codImpuestoDocSustento = null;
    public ?string $codigoPorcentaje = null;
    public float|string|null $baseImponible = null;
    public float|string|null $tarifa = null;
    public float|string|null $valorImpuesto = null;
}

class Dividendo
{
    public ?string $fechaPagoDiv = null;
    public float|string|null $imRentaSoc = null;
    public ?string $ejerFisUtDiv = null;
}

class CompraCajBanano
{
    public ?string $numCajBan = null;
    public float|string|null $precCajBan = null;
}

class RetencionLinea
{
    public ?string $codigo = null;
    public ?string $codigoRetencion = null;
    public float|string|null $baseImponible = null;
    public float|string|null $porcentajeRetener = null;
    public float|string|null $valorRetenido = null;
    public ?Dividendo $dividendos = null;
    public ?CompraCajBanano $compraCajBanano = null;
}

class DetalleImpuestoReembolso
{
    public ?string $codigo = null;
    public ?string $codigoPorcentaje = null;
    public float|string|null $tarifa = null;
    public float|string|null $baseImponibleReembolso = null;
    public float|string|null $impuestoReembolso = null;
}

class CompensacionReembolso
{
    public ?string $codigo = null;
    public float|string|null $tarifa = null;
    public float|string|null $valor = null;
}

class ReembolsoDetalle
{
    public ?string $tipoIdentificacionProveedorReembolso = null;
    public ?string $identificacionProveedorReembolso = null;
    public ?string $codPaisPagoProveedorReembolso = null;
    public ?string $tipoProveedorReembolso = null;
    public ?string $codDocReembolso = null;
    public ?string $estabDocReembolso = null;
    public ?string $ptoEmiDocReembolso = null;
    public ?string $secuencialDocReembolso = null;
    public ?string $fechaEmisionDocReembolso = null;
    public ?string $numeroautorizacionDocReemb = null;
    /** @var DetalleImpuestoReembolso[] */
    public ?array $detalleImpuestos = null;
    /** @var CompensacionReembolso[] */
    public ?array $compensacionesReembolso = null;
}

class DocsSustento
{
    public ?string $codSustento = null;
    public ?string $codDocSustento = null;
    public ?string $numDocSustento = null;
    public ?string $fechaEmisionDocSustento = null;
    public ?string $fechaRegistroContable = null;
    public ?string $numAutDocSustento = null;
    public ?string $pagoLocExt = null;
    public ?string $tipoRegi = null;
    public ?string $paisEfecPago = null;
    public ?string $aplicConvDobTrib = null;
    public ?string $pagExtSujRetNorLeg = null;
    public ?string $pagoRegFis = null;
    public float|string|null $totalSinImpuestos = null;
    public float|string|null $totalComprobantesReembolso = null;
    public float|string|null $totalBaseImponibleReembolso = null;
    public float|string|null $totalImpuestoReembolso = null;
    public float|string|null $importeTotal = null;
    /** @var ImpuestoDocSustento[] */
    public ?array $impuestosDocSustento = null;
    /** @var RetencionLinea[] */
    public ?array $retenciones = null;
    /** @var ReembolsoDetalle[] */
    public ?array $reembolsos = null;
    /** @var Pago[] */
    public ?array $pagos = null;
}

class Compensacion
{
    public ?string $codigo = null;
    public float|string|null $tarifa = null;
    public float|string|null $valor = null;
}

class RetencionFacturaLinea
{
    public ?string $codigo = null;
    public ?string $codigoPorcentaje = null;
    public float|string|null $tarifa = null;
    public float|string|null $valor = null;
}

class DestinoSustitutiva
{
    public ?string $motivoTraslado = null;
    public ?string $docAduaneroUnico = null;
    public ?string $codEstabDestino = null;
    public ?string $ruta = null;
}

class InfoSustitutivaGuia
{
    public ?string $dirPartida = null;
    public ?string $dirDestinatario = null;
    public ?string $fechaIniTransporte = null;
    public ?string $fechaFinTransporte = null;
    public ?string $razonSocialTransportista = null;
    public ?string $tipoIdentificacionTransportista = null;
    public ?string $rucTransportista = null;
    public ?string $placa = null;
    /** @var DestinoSustitutiva[] */
    public ?array $destinos = null;
}

class RubroTercero
{
    public ?string $concepto = null;
    public float|string|null $total = null;
}

class TipoNegociable
{
    public ?string $correo = null;
}

class MaquinaFiscal
{
    public ?string $marca = null;
    public ?string $modelo = null;
    public ?string $serie = null;
}

class InfoTributaria
{
    public ?string $ruc = null;
    /** Tipo de comprobante (`01`, `03`, `04`, `05`, `06`, `07`). */
    public ?string $tipoDocumento = null;
    public ?string $estab = null;
    public ?string $ptoEmi = null;
    public ?string $secuencial = null;
}

class InfoDocumento
{
    public ?string $fechaEmision = null;
    public ?string $dirEstablecimiento = null;
    public ?string $tipoIdentificacionComprador = null;
    public ?string $razonSocialComprador = null;
    public ?string $identificacionComprador = null;
    public ?string $direccionComprador = null;
    public ?string $tipoIdentificacionProveedor = null;
    public ?string $razonSocialProveedor = null;
    public ?string $identificacionProveedor = null;
    public ?string $direccionProveedor = null;
    public float|string|null $totalSinImpuestos = null;
    public ?string $moneda = null;
    public float|string|null $totalDescuento = null;
    /** @var Impuesto[] */
    public ?array $totalConImpuestos = null;
    public float|string|null $importeTotal = null;
    public float|string|null $propina = null;
    /** @var Pago[] */
    public ?array $pagos = null;
    public ?string $codDocModificado = null;
    public ?string $numDocModificado = null;
    public ?string $fechaEmisionDocSustento = null;
    public float|string|null $totalDocumentoSustento = null;
    public ?string $motivo = null;
    public float|string|null $valorModificacion = null;
    /** @var Impuesto[] */
    public ?array $impuestos = null;
    public float|string|null $valorTotal = null;
    public ?string $dirPartida = null;
    public ?string $razonSocialTransportista = null;
    public ?string $tipoIdentificacionTransportista = null;
    public ?string $rucTransportista = null;
    public ?string $fechaIniTransporte = null;
    public ?string $fechaFinTransporte = null;
    public ?string $placa = null;
    public ?string $tipoIdentificacionSujetoRetenido = null;
    public ?string $razonSocialSujetoRetenido = null;
    public ?string $identificacionSujetoRetenido = null;
    public ?string $periodoFiscal = null;
    public ?string $parteRel = null;
    public ?string $tipoSujetoRetenido = null;
    public ?string $telefono = null;
    public ?string $correo = null;
    public ?string $contribuyenteEspecial = null;
    public ?string $obligadoContabilidad = null;
    public ?string $comercioExterior = null;
    public ?string $incoTermFactura = null;
    public ?string $lugarIncoTerm = null;
    public ?string $paisOrigen = null;
    public ?string $puertoEmbarque = null;
    public ?string $puertoDestino = null;
    public ?string $paisDestino = null;
    public ?string $paisAdquisicion = null;
    public ?string $guiaRemision = null;
    public float|string|null $totalSubsidio = null;
    public ?string $incoTermTotalSinImpuestos = null;
    public ?string $codDocReembolso = null;
    public float|string|null $totalComprobantesReembolso = null;
    public float|string|null $totalBaseImponibleReembolso = null;
    public float|string|null $totalImpuestoReembolso = null;
    /** @var Compensacion[] */
    public ?array $compensaciones = null;
    public float|string|null $fleteInternacional = null;
    public float|string|null $seguroInternacional = null;
    public float|string|null $gastosAduaneros = null;
    public float|string|null $gastosTransporteOtros = null;
    public float|string|null $valorRetIva = null;
    public float|string|null $valorRetRenta = null;
    /** @var ReembolsoDetalle[] */
    public ?array $reembolsos = null;
    /** @var RetencionFacturaLinea[] */
    public ?array $retenciones = null;
    public ?InfoSustitutivaGuia $infoSustitutivaGuiaRemision = null;
    /** @var RubroTercero[] */
    public ?array $otrosRubrosTerceros = null;
    public ?TipoNegociable $tipoNegociable = null;
    public ?MaquinaFiscal $maquinaFiscal = null;
}

class ComprobanteRequest
{
    public ?string $origenReferencia = null;
    public ?string $referenciaExterna = null;
    public ?InfoTributaria $infoTributaria = null;
    public ?InfoDocumento $info = null;
    /** @var Detalle[] */
    public ?array $detalles = null;
    /** @var Motivo[] */
    public ?array $motivos = null;
    /** @var Destinatario[] */
    public ?array $destinatarios = null;
    /** @var DocsSustento[] */
    public ?array $docsSustento = null;
    /** @var CampoAdicional[] */
    public ?array $infoAdicional = null;
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

class Contribuyente
{
    /** @var array<string, mixed> Campos del API que este modelo no declara. */
    public array $extra = [];

    public ?string $identificacion = null;
    public ?string $identificacionCompleta = null;
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
    public bool $provisional = false;
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
