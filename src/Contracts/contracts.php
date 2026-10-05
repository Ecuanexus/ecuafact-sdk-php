<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

// Contratos del API publico v1.
// Se cargan via composer "autoload.files" para mantenerlos en un solo archivo.

// ---------------------------------------------------------------------------
// Solicitud
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
    public ?float $tarifa = null;
    public ?float $baseImponible = null;
    public ?float $valor = null;
    public ?float $descuentoAdicional = null;
    public ?float $valorDevolucionIva = null;
}

class Pago
{
    public ?string $formaPago = null;
    public ?float $total = null;
    public ?float $plazo = null;
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
    public ?float $cantidad = null;
    public ?float $precioUnitario = null;
    public ?float $precioSinSubsidio = null;
    public ?float $descuento = null;
    public ?float $precioTotalSinImpuesto = null;
    /** @var Impuesto[] */
    public ?array $impuestos = null;
    /** @var CampoAdicional[] */
    public ?array $detallesAdicionales = null;
}

class Motivo
{
    public ?string $razon = null;
    public ?float $valor = null;
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
    public ?float $baseImponible = null;
    public ?float $tarifa = null;
    public ?float $valorImpuesto = null;
}

class Dividendo
{
    public ?string $fechaPagoDiv = null;
    public ?float $imRentaSoc = null;
    public ?string $ejerFisUtDiv = null;
}

class CompraCajBanano
{
    public ?string $numCajBan = null;
    public ?float $precCajBan = null;
}

class RetencionLinea
{
    public ?string $codigo = null;
    public ?string $codigoRetencion = null;
    public ?float $baseImponible = null;
    public ?float $porcentajeRetener = null;
    public ?float $valorRetenido = null;
    public ?Dividendo $dividendos = null;
    public ?CompraCajBanano $compraCajBanano = null;
}

class DetalleImpuestoReembolso
{
    public ?string $codigo = null;
    public ?string $codigoPorcentaje = null;
    public ?float $tarifa = null;
    public ?float $baseImponibleReembolso = null;
    public ?float $impuestoReembolso = null;
}

class CompensacionReembolso
{
    public ?string $codigo = null;
    public ?float $tarifa = null;
    public ?float $valor = null;
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
    public ?float $totalSinImpuestos = null;
    public ?float $totalComprobantesReembolso = null;
    public ?float $totalBaseImponibleReembolso = null;
    public ?float $totalImpuestoReembolso = null;
    public ?float $importeTotal = null;
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
    public ?float $tarifa = null;
    public ?float $valor = null;
}

class RetencionFacturaLinea
{
    public ?string $codigo = null;
    public ?string $codigoPorcentaje = null;
    public ?float $tarifa = null;
    public ?float $valor = null;
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
    public ?float $total = null;
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
    public ?string $codDoc = null;
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
    public ?float $totalSinImpuestos = null;
    public ?string $moneda = null;
    public ?float $totalDescuento = null;
    /** @var Impuesto[] */
    public ?array $totalConImpuestos = null;
    public ?float $importeTotal = null;
    public ?float $propina = null;
    /** @var Pago[] */
    public ?array $pagos = null;
    public ?string $codDocModificado = null;
    public ?string $numDocModificado = null;
    public ?string $fechaEmisionDocSustento = null;
    public ?float $totalDocumentoSustento = null;
    public ?string $motivo = null;
    public ?float $valorModificacion = null;
    /** @var Impuesto[] */
    public ?array $impuestos = null;
    public ?float $valorTotal = null;
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
    public ?float $totalSubsidio = null;
    public ?string $incoTermTotalSinImpuestos = null;
    public ?string $codDocReembolso = null;
    public ?float $totalComprobantesReembolso = null;
    public ?float $totalBaseImponibleReembolso = null;
    public ?float $totalImpuestoReembolso = null;
    /** @var Compensacion[] */
    public ?array $compensaciones = null;
    public ?float $fleteInternacional = null;
    public ?float $seguroInternacional = null;
    public ?float $gastosAduaneros = null;
    public ?float $gastosTransporteOtros = null;
    public ?float $valorRetIva = null;
    public ?float $valorRetRenta = null;
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

class ApiError
{
    public ?string $codigo = null;
    public ?string $mensaje = null;
    /** @var string[] */
    public ?array $errores = null;
}

class Admission
{
    public ?string $codigo = null;
    public ?string $mensaje = null;
    public ?string $idOperacion = null;
    public ?string $urlEstado = null;
    public ?string $uid = null;
}

class Operation
{
    public ?string $idOperacion = null;
    public ?int $ambiente = null;
    public ?string $codDoc = null;
    public ?string $estado = null;
    public ?string $claveAcceso = null;
    public ?string $codigoError = null;
    public ?string $fechaCreacion = null;
    public ?string $fechaActualizacion = null;
    public ?string $estadoAutorizacion = null;
    public ?string $fechaAutorizacion = null;
    public ?string $fechaConsulta = null;
    public ?string $codigoErrorConsulta = null;
}

class QuotaBucket
{
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
    public ?string $identificacion = null;
    public ?string $identificacionCompleta = null;
    public ?bool $puedeEmitir = null;
    public ?bool $puedeRecibir = null;
    /** @var string[] */
    public ?array $tiposComprobante = null;
}

class Contexto
{
    public ?string $idCliente = null;
    public ?string $nombre = null;
    /** @var Contribuyente[] */
    public ?array $contribuyentes = null;
}

class Comprobante
{
    public ?string $claveAcceso = null;
    public ?string $numeroDocumento = null;
    public ?string $codDoc = null;
    public ?string $fechaEmision = null;
    public ?string $identificacionContraparte = null;
    public ?string $razonSocialContraparte = null;
    public ?float $total = null;
    public ?string $estadoAutorizacion = null;
    public ?string $fechaAutorizacion = null;
    public ?string $codigoError = null;
    public ?string $fechaExpress = null;
    public ?int $idExpressDocument = null;
}

class PaginaComprobantes
{
    /** @var Comprobante[] */
    public ?array $comprobantes = null;
    public ?int $pagina = null;
    public ?int $tamanoPagina = null;
    public ?bool $hayMas = null;
}

// ---------------------------------------------------------------------------
// Consultas externas del SRI (respuestas planas)
// ---------------------------------------------------------------------------

class RepresentanteLegalConsulta
{
    public ?string $identificacion = null;
    public ?string $nombre = null;
}

class EstablecimientoConsulta
{
    public ?string $numero = null;
    public ?string $nombreComercial = null;
    public ?string $tipo = null;
    public ?string $direccionCompleta = null;
    public ?string $estado = null;
    public ?bool $esMatriz = null;
}

class EstadoTributario
{
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
    public ?string $identificacion = null;
    public ?string $tipoIdentificacion = null;
    public ?string $nombreCompleto = null;
    public ?string $clase = null;
    public ?string $estado = null;
    public ?string $fuenteOrigen = null;
}

class EstablecimientosContribuyente
{
    public ?string $ruc = null;
    public ?string $filtro = null;
    public ?int $cantidad = null;
    /** @var EstablecimientoConsulta[] */
    public ?array $establecimientos = null;
}

class ValidacionIdentificacion
{
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
    public ?string $identificacion = null;
    public ?string $razonSocial = null;
    public ?string $nombreComercial = null;
    public ?string $direccionMatriz = null;
    public ?string $correo = null;
    public ?string $telefono = null;
    public ?string $ciudad = null;
    public ?string $provincia = null;
    public ?string $logo = null;
}

class CorreoSolicitud
{
    public string $destinatario = '';
}

class CorreoEnviado
{
    public string $claveAcceso = '';
    public string $destinatario = '';
}

class CatalogoItem
{
    public string $codigo = '';
    public string $nombre = '';
    public ?float $tarifa = null;
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
