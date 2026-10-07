<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\Detalle;
use Ecuafact\Sdk\Contracts\Impuesto;
use Ecuafact\Sdk\Contracts\InfoDocumento;
use Ecuafact\Sdk\Contracts\Pago;
use Ecuafact\Sdk\Support\DecimalMath as D;

/**
 * Calcula los valores vacios de un comprobante y detecta inconsistencias, con las reglas del API
 * (guia "Como calcular los totales"):
 *
 * - subtotal de linea: `redondear(cantidad x precioUnitario - descuento)`;
 * - ICE de la linea: `redondear((cantidad x precioUnitario - descuento) x tarifa / 100)`;
 * - IVA de la linea: sobre la base SIN redondear mas el ICE de la linea, redondeado;
 *   su `baseImponible` es el subtotal de la linea mas el ICE;
 * - `totalConImpuestos`: una fila por `codigo` + `codigoPorcentaje`, sumando los valores de las lineas;
 * - total: subtotal + IVA + ICE + propina; un pago sin `total` recibe lo que falta para el total.
 *
 * Nunca cambia un valor que ya tiene contenido: solo rellena los `null`.
 */
final class CalculadoraComprobante
{
    private const TIPOS = ['01', '03', '04', '05', '06', '07'];

    /** @var Inconsistencia[] */
    private array $errores = [];

    public function __construct(private readonly Precision $precision = new Precision())
    {
    }

    // -----------------------------------------------------------------------------------------
    // Calculo
    // -----------------------------------------------------------------------------------------

    /** Rellena (en el mismo objeto) los valores vacios que se pueden calcular. */
    public function calcular(ComprobanteRequest $req): void
    {
        $codDoc = $req->infoTributaria?->codDoc;
        if ($codDoc === '06' || !in_array($codDoc, self::TIPOS, true)) {
            return;
        }
        $req->info ??= new InfoDocumento();
        $info = $req->info;
        match ($codDoc) {
            '01', '03', '04' => $this->calcularLineas($req, $info, $codDoc),
            '05' => $this->calcularNotaDebito($req, $info),
            '07' => $this->calcularRetencion($req),
        };
    }

    private function calcularLineas(ComprobanteRequest $req, InfoDocumento $info, string $codDoc): void
    {
        $info->moneda ??= 'DOLAR';
        $subtotal = '0';
        $descuentos = '0';
        $iva = '0';
        $ice = '0';
        $completo = true;
        /** @var array<string, array{codigo: string, codigoPorcentaje: string, tarifa: mixed, base: ?string, valor: ?string}> $grupos */
        $grupos = [];
        foreach ($req->detalles ?? [] as $detalle) {
            if (!$detalle instanceof Detalle) {
                $completo = false;
                continue;
            }
            $detalle->descuento ??= $this->cero();
            $bruto = $this->bruto($detalle);
            if ($bruto === null) {
                $completo = false;
                continue;
            }
            $detalle->precioTotalSinImpuesto ??= $this->redondear($bruto);
            $sub = D::normalizar($detalle->precioTotalSinImpuesto, 'precioTotalSinImpuesto');
            $subtotal = D::sumar($subtotal, $sub);
            $descuentos = D::sumar($descuentos, D::normalizar($detalle->descuento, 'descuento'));

            $iceLinea = '0';
            $impuestos = $detalle->impuestos ?? [];
            foreach ($impuestos as $impuesto) {
                if ($impuesto instanceof Impuesto && $impuesto->codigo === '3') {
                    $impuesto->baseImponible ??= $sub;
                    if ($impuesto->valor === null && $impuesto->tarifa !== null) {
                        $impuesto->valor = $this->redondear(D::porcentaje($bruto, D::normalizar($impuesto->tarifa, 'tarifa')));
                    }
                    if ($impuesto->valor !== null) {
                        $iceLinea = D::sumar($iceLinea, D::normalizar($impuesto->valor, 'valor'));
                    }
                }
            }
            foreach ($impuestos as $impuesto) {
                if (!$impuesto instanceof Impuesto || $impuesto->codigo === '3') {
                    continue;
                }
                $esIva = $impuesto->codigo === '2';
                $impuesto->baseImponible ??= $esIva ? D::sumar($sub, $iceLinea) : $sub;
                if ($impuesto->valor === null && $impuesto->tarifa !== null) {
                    $base = $esIva ? D::sumar($bruto, $iceLinea) : $bruto;
                    $impuesto->valor = $this->redondear(D::porcentaje($base, D::normalizar($impuesto->tarifa, 'tarifa')));
                }
                if ($esIva && $impuesto->valor !== null) {
                    $iva = D::sumar($iva, D::normalizar($impuesto->valor, 'valor'));
                }
            }
            $ice = D::sumar($ice, $iceLinea);
            foreach ($impuestos as $impuesto) {
                if ($impuesto instanceof Impuesto) {
                    $this->agrupar($grupos, $impuesto);
                }
            }
        }
        if ($req->detalles === null || $req->detalles === []) {
            return;
        }

        $this->rellenarCabecera($info->totalConImpuestos, $grupos);
        if (!$completo) {
            return;
        }
        $info->totalSinImpuestos ??= $subtotal;
        if ($codDoc !== '04') {
            $info->totalDescuento ??= $descuentos;
        }
        $propina = '0';
        if ($codDoc === '01') {
            $info->propina ??= $this->cero();
            $propina = D::normalizar($info->propina, 'propina');
        }
        $total = D::sumar(D::sumar(D::sumar(D::normalizar($info->totalSinImpuestos, 'totalSinImpuestos'), $iva), $ice), $propina);
        if ($codDoc === '04') {
            $info->valorModificacion ??= $total;
            return;
        }
        $info->importeTotal ??= $total;
        $this->rellenarPagos($info->pagos, D::normalizar($info->importeTotal, 'importeTotal'));
    }

    private function calcularNotaDebito(ComprobanteRequest $req, InfoDocumento $info): void
    {
        $suma = '0';
        $completo = true;
        $valores = [];
        foreach ($req->motivos ?? [] as $motivo) {
            if ($motivo->valor === null) {
                $completo = false;
                continue;
            }
            $valor = D::normalizar($motivo->valor, 'valor');
            $valores[] = $valor;
            $suma = D::sumar($suma, $valor);
        }
        if (!$completo || $valores === []) {
            return;
        }
        $info->totalSinImpuestos ??= $suma;
        $ivaTotal = '0';
        foreach ($info->impuestos ?? [] as $impuesto) {
            if (!$impuesto instanceof Impuesto) {
                continue;
            }
            $impuesto->baseImponible ??= $suma;
            if ($impuesto->valor === null && $impuesto->tarifa !== null) {
                $tarifa = D::normalizar($impuesto->tarifa, 'tarifa');
                $valor = '0';
                foreach ($valores as $v) {
                    $valor = D::sumar($valor, $this->redondear(D::porcentaje($v, $tarifa)));
                }
                $impuesto->valor = $this->redondear($valor);
            }
            if ($impuesto->valor !== null) {
                $ivaTotal = D::sumar($ivaTotal, D::normalizar($impuesto->valor, 'valor'));
            }
        }
        $info->valorTotal ??= D::sumar(D::normalizar($info->totalSinImpuestos, 'totalSinImpuestos'), $ivaTotal);
        $this->rellenarPagos($info->pagos, D::normalizar($info->valorTotal, 'valorTotal'));
    }

    private function calcularRetencion(ComprobanteRequest $req): void
    {
        foreach ($req->docsSustento ?? [] as $doc) {
            $doc->pagoLocExt ??= '01';
            $impuestos = '0';
            $bases = '0';
            $completo = true;
            foreach ($doc->impuestosDocSustento ?? [] as $impuesto) {
                if ($impuesto->valorImpuesto === null && $impuesto->baseImponible !== null && $impuesto->tarifa !== null) {
                    $impuesto->valorImpuesto = $this->redondear(D::porcentaje(
                        D::normalizar($impuesto->baseImponible, 'baseImponible'),
                        D::normalizar($impuesto->tarifa, 'tarifa')
                    ));
                }
                if ($impuesto->baseImponible !== null && $bases !== null) {
                    $bases = D::sumar($bases, D::normalizar($impuesto->baseImponible, 'baseImponible'));
                } else {
                    $bases = null;
                }
                if ($impuesto->valorImpuesto === null) {
                    $completo = false;
                    continue;
                }
                $impuestos = D::sumar($impuestos, D::normalizar($impuesto->valorImpuesto, 'valorImpuesto'));
            }
            foreach ($doc->retenciones ?? [] as $retencion) {
                if ($retencion->valorRetenido === null && $retencion->baseImponible !== null && $retencion->porcentajeRetener !== null) {
                    $retencion->valorRetenido = $this->redondear(D::porcentaje(
                        D::normalizar($retencion->baseImponible, 'baseImponible'),
                        D::normalizar($retencion->porcentajeRetener, 'porcentajeRetener')
                    ));
                }
            }
            if ($doc->totalSinImpuestos === null && $bases !== null && ($doc->impuestosDocSustento ?? []) !== []) {
                $doc->totalSinImpuestos = $bases;
            }
            if ($doc->importeTotal === null && $completo && $doc->totalSinImpuestos !== null && $doc->impuestosDocSustento !== null) {
                $doc->importeTotal = D::sumar(D::normalizar($doc->totalSinImpuestos, 'totalSinImpuestos'), $impuestos);
            }
            if ($doc->importeTotal !== null) {
                $this->rellenarPagos($doc->pagos, D::normalizar($doc->importeTotal, 'importeTotal'));
            }
        }
    }

    /**
     * @param array<string, array{codigo: string, codigoPorcentaje: string, tarifa: mixed, base: ?string, valor: ?string}> $grupos
     */
    private function agrupar(array &$grupos, Impuesto $impuesto): void
    {
        $clave = ($impuesto->codigo ?? '') . '|' . ($impuesto->codigoPorcentaje ?? '');
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'codigo' => (string) $impuesto->codigo,
                'codigoPorcentaje' => (string) $impuesto->codigoPorcentaje,
                'tarifa' => $impuesto->tarifa,
                'base' => '0',
                'valor' => '0',
            ];
        }
        $grupo = &$grupos[$clave];
        $grupo['base'] = $grupo['base'] === null || $impuesto->baseImponible === null
            ? null : D::sumar($grupo['base'], D::normalizar($impuesto->baseImponible, 'baseImponible'));
        $grupo['valor'] = $grupo['valor'] === null || $impuesto->valor === null
            ? null : D::sumar($grupo['valor'], D::normalizar($impuesto->valor, 'valor'));
    }

    /**
     * @param Impuesto[]|null $cabecera
     * @param array<string, array{codigo: string, codigoPorcentaje: string, tarifa: mixed, base: ?string, valor: ?string}> $grupos
     */
    private function rellenarCabecera(?array &$cabecera, array $grupos): void
    {
        $ordenados = self::ordenarGrupos($grupos);
        if ($cabecera === null) {
            $cabecera = [];
            foreach ($ordenados as $grupo) {
                $fila = new Impuesto();
                $fila->codigo = $grupo['codigo'];
                $fila->codigoPorcentaje = $grupo['codigoPorcentaje'];
                $fila->tarifa = $grupo['tarifa'];
                $fila->baseImponible = $grupo['base'];
                $fila->valor = $grupo['valor'];
                $cabecera[] = $fila;
            }
            return;
        }
        foreach ($cabecera as $fila) {
            if (!$fila instanceof Impuesto) {
                continue;
            }
            $grupo = $grupos[($fila->codigo ?? '') . '|' . ($fila->codigoPorcentaje ?? '')] ?? null;
            if ($grupo === null) {
                continue;
            }
            $fila->tarifa ??= $grupo['tarifa'];
            $fila->baseImponible ??= $grupo['base'];
            $fila->valor ??= $grupo['valor'];
        }
    }

    /**
     * IVA (`2`) antes que ICE (`3`); dentro de cada impuesto, en el orden en que aparecen.
     *
     * @param array<string, array{codigo: string, codigoPorcentaje: string, tarifa: mixed, base: ?string, valor: ?string}> $grupos
     * @return list<array{codigo: string, codigoPorcentaje: string, tarifa: mixed, base: ?string, valor: ?string}>
     */
    private static function ordenarGrupos(array $grupos): array
    {
        $lista = array_values($grupos);
        $indices = array_keys($lista);
        usort($indices, static fn (int $a, int $b): int => [$lista[$a]['codigo'], $a] <=> [$lista[$b]['codigo'], $b]);
        return array_map(static fn (int $i): array => $lista[$i], $indices);
    }

    /** @param Pago[]|null $pagos */
    private function rellenarPagos(?array $pagos, string $total): void
    {
        if ($pagos === null || $pagos === []) {
            return;
        }
        $sinTotal = [];
        $suma = '0';
        foreach ($pagos as $pago) {
            if (!$pago instanceof Pago) {
                return;
            }
            if ($pago->total === null) {
                $sinTotal[] = $pago;
                continue;
            }
            $suma = D::sumar($suma, D::normalizar($pago->total, 'total'));
        }
        if (count($sinTotal) === 1) {
            $sinTotal[0]->total = $this->redondear(D::restar($total, $suma));
        }
    }

    private function bruto(Detalle $detalle): ?string
    {
        if ($detalle->cantidad === null || $detalle->precioUnitario === null) {
            return null;
        }
        return D::restar(
            D::multiplicar(D::normalizar($detalle->cantidad, 'cantidad'), D::normalizar($detalle->precioUnitario, 'precioUnitario')),
            D::normalizar($detalle->descuento ?? '0', 'descuento')
        );
    }

    private function redondear(string $valor): string
    {
        return D::redondear($valor, $this->precision->escalaImportes, $this->precision->redondeo);
    }

    private function cero(): string
    {
        return D::redondear('0', $this->precision->escalaImportes);
    }

    // -----------------------------------------------------------------------------------------
    // Validacion
    // -----------------------------------------------------------------------------------------

    /**
     * Devuelve las inconsistencias del comprobante (no lanza). `$codDocEsperado` es el tipo del
     * constructor; si es null se usa el `codDoc` del comprobante.
     *
     * @return Inconsistencia[]
     */
    public function validar(ComprobanteRequest $req, ?string $codDocEsperado = null): array
    {
        $this->errores = [];
        $this->validarComun($req, $codDocEsperado);
        $codDoc = $codDocEsperado ?? $req->infoTributaria?->codDoc;
        $info = $req->info;
        if ($info === null) {
            $this->error('info', 'Es obligatorio.');
            return $this->errores;
        }
        match ($codDoc) {
            '01', '03', '04' => $this->validarLineas($req, $info, $codDoc),
            '05' => $this->validarNotaDebito($req, $info),
            '06' => $this->validarGuia($req, $info),
            '07' => $this->validarRetencion($req, $info),
            default => null,
        };
        return $this->errores;
    }

    private function validarComun(ComprobanteRequest $req, ?string $codDocEsperado): void
    {
        $this->patron('origenReferencia', $req->origenReferencia, '/^[A-Za-z0-9\-_.:\/]{1,64}$/D',
            'De 1 a 64 caracteres: letras y digitos ASCII y - _ . : /.');
        $this->patron('referenciaExterna', $req->referenciaExterna, '/^[A-Za-z0-9\-_.:\/]{1,128}$/D',
            'De 1 a 128 caracteres: letras y digitos ASCII y - _ . : /.');
        $it = $req->infoTributaria;
        if ($it === null) {
            $this->error('infoTributaria', 'Es obligatorio.');
            return;
        }
        $this->patron('infoTributaria.ruc', $it->ruc, '/^\d{5,13}$/D', 'Solo digitos (RUC de 13 digitos).');
        if ($it->codDoc === null || $it->codDoc === '') {
            $this->error('infoTributaria.codDoc', 'Es obligatorio.');
        } elseif (!in_array($it->codDoc, self::TIPOS, true)) {
            $this->error('infoTributaria.codDoc', 'Tipo no admitido: usa 01, 03, 04, 05, 06 o 07.');
        } elseif ($codDocEsperado !== null && $it->codDoc !== $codDocEsperado) {
            $this->error('infoTributaria.codDoc', 'Debe ser ' . $codDocEsperado . ' para este constructor.');
        }
        $this->patron('infoTributaria.estab', $it->estab, '/^\d{3}$/D', 'Debe tener 3 digitos (por ejemplo 001).');
        $this->patron('infoTributaria.ptoEmi', $it->ptoEmi, '/^\d{3}$/D', 'Debe tener 3 digitos (por ejemplo 001).');
        if ($it->secuencial !== null) {
            $this->patron('infoTributaria.secuencial', $it->secuencial, '/^\d{1,9}$/D', 'Hasta 9 digitos.');
        }
        $adicionales = $req->infoAdicional ?? [];
        if (count($adicionales) > 20) {
            $this->error('infoAdicional', 'Maximo 20 campos.');
        }
        foreach ($adicionales as $i => $campo) {
            $this->requerido("infoAdicional[$i].nombre", $campo->nombre);
            $this->requerido("infoAdicional[$i].valor", $campo->valor);
        }
    }

    private function validarLineas(ComprobanteRequest $req, InfoDocumento $info, string $codDoc): void
    {
        $this->fecha('info.fechaEmision', $info->fechaEmision);
        if ($codDoc === '03') {
            $this->requerido('info.tipoIdentificacionProveedor', $info->tipoIdentificacionProveedor);
            $this->requerido('info.identificacionProveedor', $info->identificacionProveedor);
            $this->requerido('info.razonSocialProveedor', $info->razonSocialProveedor);
        } else {
            $this->requerido('info.tipoIdentificacionComprador', $info->tipoIdentificacionComprador);
            $this->requerido('info.identificacionComprador', $info->identificacionComprador);
            $this->requerido('info.razonSocialComprador', $info->razonSocialComprador);
        }
        $this->moneda($info->moneda, true);
        if ($codDoc === '04') {
            $this->documentoModificado($info);
            $this->requerido('info.motivo', $info->motivo);
        }

        $detalles = $req->detalles ?? [];
        if ($detalles === [] || count($detalles) > 1000) {
            $this->error('detalles', 'Se requieren entre 1 y 1000 lineas.');
            return;
        }
        $subtotal = '0';
        $descuentos = '0';
        $iva = '0';
        $ice = '0';
        $completo = true;
        $grupos = [];
        foreach ($detalles as $i => $d) {
            $ruta = "detalles[$i]";
            $this->requerido($ruta . ($codDoc === '04' ? '.codigoInterno' : '.codigoPrincipal'),
                $codDoc === '04' ? $d->codigoInterno : $d->codigoPrincipal);
            $this->requerido("$ruta.descripcion", $d->descripcion);
            $cantidad = $this->numero("$ruta.cantidad", $d->cantidad, $this->precision->escalaCantidades);
            $precio = $this->numero("$ruta.precioUnitario", $d->precioUnitario, $this->precision->escalaCantidades);
            $descuento = $this->numero("$ruta.descuento", $d->descuento, $this->precision->escalaImportes);
            $sub = $this->numero("$ruta.precioTotalSinImpuesto", $d->precioTotalSinImpuesto, $this->precision->escalaImportes);
            if ($cantidad !== null && D::comparar($cantidad, '0') <= 0) {
                $this->error("$ruta.cantidad", 'Debe ser mayor que 0.');
            }
            if ($precio !== null && D::comparar($precio, '0') < 0) {
                $this->error("$ruta.precioUnitario", 'No puede ser negativo.');
            }
            if ($descuento !== null && D::comparar($descuento, '0') < 0) {
                $this->error("$ruta.descuento", 'No puede ser negativo.');
            }
            if ($cantidad === null || $precio === null || $descuento === null || $sub === null) {
                $completo = false;
                continue;
            }
            $bruto = D::restar(D::multiplicar($cantidad, $precio), $descuento);
            $this->igual("$ruta.precioTotalSinImpuesto", $sub, $this->redondear($bruto),
                'No coincide con cantidad x precioUnitario - descuento');
            $subtotal = D::sumar($subtotal, $sub);
            $descuentos = D::sumar($descuentos, $descuento);

            $impuestos = $d->impuestos ?? [];
            $ivas = array_filter($impuestos, static fn ($t) => $t instanceof Impuesto && $t->codigo === '2');
            $ices = array_filter($impuestos, static fn ($t) => $t instanceof Impuesto && $t->codigo === '3');
            if (count($ivas) !== 1 || count($ices) > 1 || count($ivas) + count($ices) !== count($impuestos)) {
                $this->error("$ruta.impuestos", 'Debe tener exactamente un IVA (codigo 2) y como maximo un ICE (codigo 3).');
            }
            $iceLinea = '0';
            foreach ($impuestos as $j => $t) {
                if ($t instanceof Impuesto && $t->codigo === '3') {
                    $valor = $this->numero("$ruta.impuestos[$j].valor", $t->valor, $this->precision->escalaImportes);
                    $tarifa = $this->numero("$ruta.impuestos[$j].tarifa", $t->tarifa, null);
                    $this->numero("$ruta.impuestos[$j].baseImponible", $t->baseImponible, $this->precision->escalaImportes);
                    if ($valor !== null) {
                        $iceLinea = D::sumar($iceLinea, $valor);
                        if ($tarifa !== null) {
                            $this->igual("$ruta.impuestos[$j].valor", $valor, $this->redondear(D::porcentaje($bruto, $tarifa)),
                                'No coincide con base y tarifa');
                        }
                    } else {
                        $completo = false;
                    }
                }
            }
            foreach ($impuestos as $j => $t) {
                if (!$t instanceof Impuesto || $t->codigo === '3') {
                    continue;
                }
                $rutaT = "$ruta.impuestos[$j]";
                $this->requerido("$rutaT.codigoPorcentaje", $t->codigoPorcentaje);
                $valor = $this->numero("$rutaT.valor", $t->valor, $this->precision->escalaImportes);
                $tarifa = $this->numero("$rutaT.tarifa", $t->tarifa, null);
                $base = $this->numero("$rutaT.baseImponible", $t->baseImponible, $this->precision->escalaImportes);
                if ($t->codigo !== '2') {
                    $this->error("$rutaT.codigo", 'Solo se admiten IVA (2) e ICE (3).');
                    continue;
                }
                if ($base !== null) {
                    $this->igual("$rutaT.baseImponible", $base, D::sumar($sub, $iceLinea), 'Debe ser el subtotal de la linea mas el ICE');
                }
                if ($tarifa !== null) {
                    $cero = in_array($t->codigoPorcentaje, ['0', '6', '7'], true);
                    if ($cero && D::comparar($tarifa, '0') !== 0) {
                        $this->error("$rutaT.tarifa", 'Con codigoPorcentaje ' . $t->codigoPorcentaje . ' la tarifa debe ser 0.');
                    } elseif (!$cero && D::comparar($tarifa, '0') <= 0) {
                        $this->error("$rutaT.tarifa", 'El IVA gravado requiere una tarifa mayor que 0.');
                    }
                }
                if ($valor === null) {
                    $completo = false;
                    continue;
                }
                if ($tarifa !== null) {
                    $this->igual("$rutaT.valor", $valor, $this->redondear(D::porcentaje(D::sumar($bruto, $iceLinea), $tarifa)),
                        'No coincide con base (sin redondear) y tarifa');
                }
                $iva = D::sumar($iva, $valor);
            }
            $ice = D::sumar($ice, $iceLinea);
            foreach ($impuestos as $t) {
                if ($t instanceof Impuesto) {
                    $this->agrupar($grupos, $t);
                }
            }
        }

        $this->validarCabecera($info->totalConImpuestos, $grupos);
        $tsi = $this->numero('info.totalSinImpuestos', $info->totalSinImpuestos, $this->precision->escalaImportes);
        if ($tsi !== null && $completo) {
            $this->igual('info.totalSinImpuestos', $tsi, $subtotal, 'No coincide con la suma de precioTotalSinImpuesto');
        }
        if ($codDoc !== '04') {
            $td = $this->numero('info.totalDescuento', $info->totalDescuento, $this->precision->escalaImportes);
            if ($td !== null && $completo) {
                $this->igual('info.totalDescuento', $td, $descuentos, 'No coincide con la suma de los descuentos');
            }
        }
        $propina = '0';
        if ($codDoc === '01') {
            $propina = $this->numero('info.propina', $info->propina, $this->precision->escalaImportes);
            if ($propina !== null && D::comparar($propina, '0') < 0) {
                $this->error('info.propina', 'No puede ser negativa.');
            }
            $this->comprador01($info);
        }
        $campoTotal = $codDoc === '04' ? 'valorModificacion' : 'importeTotal';
        $total = $this->numero('info.' . $campoTotal, $codDoc === '04' ? $info->valorModificacion : $info->importeTotal,
            $this->precision->escalaImportes);
        if ($total !== null && $tsi !== null && $propina !== null && $completo) {
            $this->igual('info.' . $campoTotal, $total, D::sumar(D::sumar(D::sumar($tsi, $iva), $ice), $propina),
                'No coincide con totalSinImpuestos + IVA + ICE' . ($codDoc === '01' ? ' + propina' : ''));
        }
        if ($codDoc === '04') {
            if ($info->totalDocumentoSustento !== null && $total !== null) {
                $sustento = $this->numero('info.totalDocumentoSustento', $info->totalDocumentoSustento, $this->precision->escalaImportes);
                if ($sustento !== null && D::comparar($sustento, '0') > 0 && D::comparar($total, $sustento) > 0) {
                    $this->error('info.valorModificacion', 'No puede superar totalDocumentoSustento.');
                }
            }
            return;
        }
        if ($codDoc === '01' && $info->tipoIdentificacionComprador === '07' && $total !== null && D::comparar($total, '50') > 0) {
            $this->error('info.tipoIdentificacionComprador', 'Consumidor final solo hasta 50.00: identifica al comprador.');
        }
        $this->pagos('info.pagos', $info->pagos, $total, 'info.importeTotal');
    }

    /** @param array<string, array{codigo: string, codigoPorcentaje: string, tarifa: mixed, base: ?string, valor: ?string}> $grupos */
    private function validarCabecera(?array $cabecera, array $grupos): void
    {
        if ($cabecera === null || $cabecera === []) {
            $this->error('info.totalConImpuestos', 'Es obligatorio: una fila por cada codigo + codigoPorcentaje de las lineas.');
            return;
        }
        $vistos = [];
        foreach ($cabecera as $i => $fila) {
            $ruta = "info.totalConImpuestos[$i]";
            $clave = ($fila->codigo ?? '') . '|' . ($fila->codigoPorcentaje ?? '');
            if (isset($vistos[$clave])) {
                $this->error($ruta, 'Fila repetida para codigo ' . $fila->codigo . ' y codigoPorcentaje ' . $fila->codigoPorcentaje . '.');
                continue;
            }
            $vistos[$clave] = true;
            $grupo = $grupos[$clave] ?? null;
            if ($grupo === null) {
                $this->error($ruta, 'Ninguna linea tiene codigo ' . $fila->codigo . ' y codigoPorcentaje ' . $fila->codigoPorcentaje . '.');
                continue;
            }
            $base = $this->numero("$ruta.baseImponible", $fila->baseImponible, $this->precision->escalaImportes);
            $valor = $this->numero("$ruta.valor", $fila->valor, $this->precision->escalaImportes);
            if ($base !== null && $grupo['base'] !== null) {
                $this->igual("$ruta.baseImponible", $base, $grupo['base'], 'No coincide con la suma de las bases de las lineas');
            }
            if ($valor !== null && $grupo['valor'] !== null) {
                $this->igual("$ruta.valor", $valor, $grupo['valor'], 'No coincide con la suma de los valores de las lineas');
            }
            if ($fila->tarifa !== null && $grupo['tarifa'] !== null && D::esNumero($fila->tarifa) && D::esNumero($grupo['tarifa'])
                && !D::iguales(D::normalizar($fila->tarifa), D::normalizar($grupo['tarifa']))) {
                $this->error("$ruta.tarifa", 'Debe ser igual a la tarifa de las lineas.');
            }
        }
        foreach ($grupos as $clave => $grupo) {
            if (!isset($vistos[$clave])) {
                $this->error('info.totalConImpuestos', 'Falta la fila de codigo ' . $grupo['codigo'] . ' y codigoPorcentaje ' . $grupo['codigoPorcentaje'] . '.');
            }
        }
    }

    private function validarNotaDebito(ComprobanteRequest $req, InfoDocumento $info): void
    {
        $this->fecha('info.fechaEmision', $info->fechaEmision);
        $this->requerido('info.tipoIdentificacionComprador', $info->tipoIdentificacionComprador);
        $this->requerido('info.identificacionComprador', $info->identificacionComprador);
        $this->requerido('info.razonSocialComprador', $info->razonSocialComprador);
        $this->documentoModificado($info);
        $this->moneda($info->moneda, false);
        $motivos = $req->motivos ?? [];
        if ($motivos === [] || count($motivos) > 1000) {
            $this->error('motivos', 'Se requieren entre 1 y 1000 motivos.');
            return;
        }
        $suma = '0';
        $valores = [];
        $completo = true;
        foreach ($motivos as $i => $m) {
            $this->requerido("motivos[$i].razon", $m->razon);
            $v = $this->numero("motivos[$i].valor", $m->valor, $this->precision->escalaImportes);
            if ($v === null) {
                $completo = false;
                continue;
            }
            if (D::comparar($v, '0') < 0) {
                $this->error("motivos[$i].valor", 'No puede ser negativo.');
            }
            $valores[] = $v;
            $suma = D::sumar($suma, $v);
        }
        $tsi = $this->numero('info.totalSinImpuestos', $info->totalSinImpuestos, $this->precision->escalaImportes);
        if ($tsi !== null && $completo) {
            $this->igual('info.totalSinImpuestos', $tsi, $suma, 'No coincide con la suma de motivos[].valor');
        }
        $impuestos = $info->impuestos ?? [];
        $ivaValor = null;
        if (count($impuestos) !== 1 || ($impuestos[0]->codigo ?? null) !== '2') {
            $this->error('info.impuestos', 'Debe tener exactamente un IVA (codigo 2).');
        } else {
            $t = $impuestos[0];
            $this->requerido('info.impuestos[0].codigoPorcentaje', $t->codigoPorcentaje);
            $base = $this->numero('info.impuestos[0].baseImponible', $t->baseImponible, $this->precision->escalaImportes);
            $tarifa = $this->numero('info.impuestos[0].tarifa', $t->tarifa, null);
            $ivaValor = $this->numero('info.impuestos[0].valor', $t->valor, $this->precision->escalaImportes);
            if ($base !== null && $completo) {
                $this->igual('info.impuestos[0].baseImponible', $base, $suma, 'No coincide con la suma de motivos[].valor');
            }
            if ($ivaValor !== null && $tarifa !== null && $completo) {
                $esperado = '0';
                foreach ($valores as $v) {
                    $esperado = D::sumar($esperado, $this->redondear(D::porcentaje($v, $tarifa)));
                }
                $this->igual('info.impuestos[0].valor', $ivaValor, $esperado, 'No coincide con la suma del IVA de cada motivo');
            }
        }
        $total = $this->numero('info.valorTotal', $info->valorTotal, $this->precision->escalaImportes);
        if ($total !== null && $tsi !== null && $ivaValor !== null) {
            $this->igual('info.valorTotal', $total, D::sumar($tsi, $ivaValor), 'No coincide con totalSinImpuestos + IVA');
        }
        $this->pagos('info.pagos', $info->pagos, $total, 'info.valorTotal');
    }

    private function validarGuia(ComprobanteRequest $req, InfoDocumento $info): void
    {
        $this->requerido('info.dirPartida', $info->dirPartida);
        $this->requerido('info.razonSocialTransportista', $info->razonSocialTransportista);
        $this->requerido('info.tipoIdentificacionTransportista', $info->tipoIdentificacionTransportista);
        $this->requerido('info.rucTransportista', $info->rucTransportista);
        $this->requerido('info.placa', $info->placa);
        $inicio = $this->fecha('info.fechaIniTransporte', $info->fechaIniTransporte);
        $fin = $this->fecha('info.fechaFinTransporte', $info->fechaFinTransporte);
        if ($inicio !== null && $fin !== null && $fin < $inicio) {
            $this->error('info.fechaFinTransporte', 'No puede ser anterior a fechaIniTransporte.');
        }
        $destinatarios = $req->destinatarios ?? [];
        if (count($destinatarios) !== 1) {
            $this->error('destinatarios', 'Debe tener exactamente un destinatario.');
            if ($destinatarios === []) {
                return;
            }
        }
        foreach ($destinatarios as $i => $dest) {
            $ruta = "destinatarios[$i]";
            $this->patron("$ruta.identificacionDestinatario", $dest->identificacionDestinatario, '/^(\d{10}|\d{13})$/D',
                'Cedula (10 digitos) o RUC (13 digitos).');
            $this->requerido("$ruta.razonSocialDestinatario", $dest->razonSocialDestinatario);
            $this->requerido("$ruta.dirDestinatario", $dest->dirDestinatario);
            $this->requerido("$ruta.motivoTraslado", $dest->motivoTraslado);
            if ($dest->codEstabDestino !== null) {
                $this->patron("$ruta.codEstabDestino", $dest->codEstabDestino, '/^\d{1,3}$/D', 'Hasta 3 digitos.');
            }
            if ($dest->ruta !== null && trim($dest->ruta) === '') {
                $this->error("$ruta.ruta", 'No puede ser solo espacios.');
            }
            $detalles = $dest->detalles ?? [];
            if ($detalles === [] || count($detalles) > 1000) {
                $this->error("$ruta.detalles", 'Se requieren entre 1 y 1000 bienes.');
                continue;
            }
            foreach ($detalles as $j => $d) {
                $this->requerido("$ruta.detalles[$j].codigoInterno", $d->codigoInterno);
                $this->requerido("$ruta.detalles[$j].descripcion", $d->descripcion);
                $cantidad = $this->numero("$ruta.detalles[$j].cantidad", $d->cantidad, $this->precision->escalaCantidades);
                if ($cantidad !== null && D::comparar($cantidad, '0') <= 0) {
                    $this->error("$ruta.detalles[$j].cantidad", 'Debe ser mayor que 0.');
                }
            }
        }
    }

    private function validarRetencion(ComprobanteRequest $req, InfoDocumento $info): void
    {
        $this->fecha('info.fechaEmision', $info->fechaEmision);
        $this->requerido('info.tipoIdentificacionSujetoRetenido', $info->tipoIdentificacionSujetoRetenido);
        $this->requerido('info.identificacionSujetoRetenido', $info->identificacionSujetoRetenido);
        $this->requerido('info.razonSocialSujetoRetenido', $info->razonSocialSujetoRetenido);
        $this->patron('info.periodoFiscal', $info->periodoFiscal, '/^(0[1-9]|1[0-2])\/\d{4}$/D', 'Formato MM/yyyy (por ejemplo 10/2026).');
        if ($info->parteRel !== 'SI' && $info->parteRel !== 'NO') {
            $this->error('info.parteRel', 'Debe ser SI o NO.');
        }
        $docs = $req->docsSustento ?? [];
        if (count($docs) !== 1) {
            $this->error('docsSustento', 'Debe tener exactamente un documento de sustento.');
            if ($docs === []) {
                return;
            }
        }
        foreach ($docs as $i => $doc) {
            $ruta = "docsSustento[$i]";
            $this->patron("$ruta.codSustento", $doc->codSustento, '/^\d{2}$/D', 'Codigo de 2 digitos.');
            $this->patron("$ruta.codDocSustento", $doc->codDocSustento, '/^\d{2}$/D', 'Codigo de 2 digitos.');
            $this->patron("$ruta.numDocSustento", $doc->numDocSustento, '/^(\d{15}|\d{3}-\d{3}-\d{9})$/D',
                '15 digitos (001001000000123) o ddd-ddd-ddddddddd.');
            $this->fecha("$ruta.fechaEmisionDocSustento", $doc->fechaEmisionDocSustento);
            $this->fecha("$ruta.fechaRegistroContable", $doc->fechaRegistroContable);
            if ($doc->numAutDocSustento !== null) {
                $this->patron("$ruta.numAutDocSustento", $doc->numAutDocSustento, '/^\d{49}$/D', 'Debe tener 49 digitos.');
            }
            if ($doc->pagoLocExt !== '01') {
                $this->error("$ruta.pagoLocExt", 'Debe ser 01 (pago local).');
            }
            $tsi = $this->numero("$ruta.totalSinImpuestos", $doc->totalSinImpuestos, $this->precision->escalaImportes);
            $impuestos = $doc->impuestosDocSustento ?? [];
            if ($impuestos === [] || count($impuestos) > 2) {
                $this->error("$ruta.impuestosDocSustento", 'Se requieren 1 o 2 impuestos.');
            }
            $sumaImpuestos = '0';
            $completo = true;
            foreach ($impuestos as $j => $t) {
                $rutaT = "$ruta.impuestosDocSustento[$j]";
                $this->requerido("$rutaT.codImpuestoDocSustento", $t->codImpuestoDocSustento);
                $this->requerido("$rutaT.codigoPorcentaje", $t->codigoPorcentaje);
                $this->numero("$rutaT.baseImponible", $t->baseImponible, $this->precision->escalaImportes);
                $this->numero("$rutaT.tarifa", $t->tarifa, null);
                $v = $this->numero("$rutaT.valorImpuesto", $t->valorImpuesto, $this->precision->escalaImportes);
                if ($v === null) {
                    $completo = false;
                    continue;
                }
                $sumaImpuestos = D::sumar($sumaImpuestos, $v);
            }
            $total = $this->numero("$ruta.importeTotal", $doc->importeTotal, $this->precision->escalaImportes);
            if ($total !== null && $tsi !== null && $completo) {
                $this->igual("$ruta.importeTotal", $total, D::sumar($tsi, $sumaImpuestos),
                    'No coincide con totalSinImpuestos + suma de valorImpuesto');
            }
            $retenciones = $doc->retenciones ?? [];
            if ($retenciones === [] || count($retenciones) > 1000) {
                $this->error("$ruta.retenciones", 'Se requieren entre 1 y 1000 retenciones.');
            }
            foreach ($retenciones as $j => $r) {
                $rutaR = "$ruta.retenciones[$j]";
                if (!in_array($r->codigo, ['1', '2', '6'], true)) {
                    $this->error("$rutaR.codigo", 'Debe ser 1 (renta), 2 (IVA) o 6 (ISD).');
                }
                $this->patron("$rutaR.codigoRetencion", $r->codigoRetencion, '/^[A-Za-z0-9\-_.:\/]{1,8}$/D', 'Hasta 8 caracteres.');
                $base = $this->numero("$rutaR.baseImponible", $r->baseImponible, $this->precision->escalaImportes);
                $pct = $this->numero("$rutaR.porcentajeRetener", $r->porcentajeRetener, null);
                $valor = $this->numero("$rutaR.valorRetenido", $r->valorRetenido, $this->precision->escalaImportes);
                if ($pct !== null && (D::comparar($pct, '0') < 0 || D::comparar($pct, '100') > 0)) {
                    $this->error("$rutaR.porcentajeRetener", 'Debe estar entre 0 y 100.');
                }
                if ($base !== null && $pct !== null && $valor !== null) {
                    $this->igual("$rutaR.valorRetenido", $valor, $this->redondear(D::porcentaje($base, $pct)),
                        'No coincide con baseImponible x porcentajeRetener / 100');
                }
            }
            $this->pagos("$ruta.pagos", $doc->pagos, $total, "$ruta.importeTotal");
        }
    }

    private function comprador01(InfoDocumento $info): void
    {
        if ($info->tipoIdentificacionComprador === '07' && $info->identificacionComprador !== '9999999999999') {
            $this->error('info.identificacionComprador', 'Consumidor final (07) usa 9999999999999.');
        }
    }

    private function documentoModificado(InfoDocumento $info): void
    {
        $this->patron('info.codDocModificado', $info->codDocModificado, '/^\d{1,2}$/D', 'Codigo de 2 digitos (01 factura).');
        $this->patron('info.numDocModificado', $info->numDocModificado, '/^\d{3}-\d{3}-\d{9}$/D',
            'Formato ddd-ddd-ddddddddd (por ejemplo 002-001-000000123).');
        $this->fecha('info.fechaEmisionDocSustento', $info->fechaEmisionDocSustento);
    }

    private function moneda(?string $moneda, bool $obligatoria): void
    {
        if ($moneda === null || trim($moneda) === '') {
            if ($obligatoria) {
                $this->error('info.moneda', 'Es obligatoria: DOLAR o USD.');
            }
            return;
        }
        if ($moneda !== 'DOLAR' && $moneda !== 'USD') {
            $this->error('info.moneda', 'Debe ser DOLAR o USD.');
        }
    }

    /** @param Pago[]|null $pagos */
    private function pagos(string $ruta, ?array $pagos, ?string $total, string $campoTotal): void
    {
        $pagos ??= [];
        if ($pagos === [] || count($pagos) > 20) {
            $this->error($ruta, 'Se requieren entre 1 y 20 formas de pago.');
            return;
        }
        $suma = '0';
        $completo = true;
        foreach ($pagos as $i => $p) {
            $this->patron("{$ruta}[$i].formaPago", $p->formaPago, '/^\d{2}$/D', 'Codigo de 2 digitos.');
            $v = $this->numero("{$ruta}[$i].total", $p->total, $this->precision->escalaImportes);
            if ($v === null) {
                $completo = false;
            } else {
                $suma = D::sumar($suma, $v);
            }
            if ($p->plazo !== null) {
                if (!D::esNumero($p->plazo) || D::decimales(D::normalizar($p->plazo)) > 0 || D::comparar(D::normalizar($p->plazo), '0') < 0) {
                    $this->error("{$ruta}[$i].plazo", 'Debe ser un entero mayor o igual a 0.');
                } elseif (D::comparar(D::normalizar($p->plazo), '0') > 0 && ($p->unidadTiempo === null || trim($p->unidadTiempo) === '')) {
                    $this->error("{$ruta}[$i].unidadTiempo", 'Obligatoria cuando hay plazo.');
                }
            }
        }
        if ($completo && $total !== null) {
            $this->igual($ruta, $suma, $total, 'La suma de los pagos no coincide con ' . $campoTotal);
        }
    }

    private function numero(string $campo, mixed $valor, ?int $escala): ?string
    {
        if ($valor === null) {
            $this->error($campo, 'Es obligatorio.');
            return null;
        }
        if (!D::esNumero($valor)) {
            $this->error($campo, 'Debe ser un numero decimal.');
            return null;
        }
        $n = D::normalizar($valor);
        if ($escala !== null && D::decimales($n) > $escala) {
            $this->error($campo, 'Admite como maximo ' . $escala . ' decimales (el API no redondea).');
        }
        return $n;
    }

    private function igual(string $campo, string $actual, string $esperado, string $mensaje): void
    {
        if (!D::iguales($actual, $esperado)) {
            $this->errores[] = new Inconsistencia($campo, $mensaje . '.', $esperado, $actual);
        }
    }

    private function requerido(string $campo, ?string $valor): void
    {
        if ($valor === null || trim($valor) === '') {
            $this->error($campo, 'Es obligatorio.');
        }
    }

    private function patron(string $campo, ?string $valor, string $patron, string $mensaje): void
    {
        if ($valor === null || $valor === '') {
            $this->error($campo, 'Es obligatorio.');
            return;
        }
        if (preg_match($patron, $valor) !== 1) {
            $this->error($campo, $mensaje);
        }
    }

    /** Valida `dd/MM/yyyy` y devuelve `yyyyMMdd` para comparar, o null. */
    private function fecha(string $campo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            $this->error($campo, 'Es obligatoria (dd/MM/yyyy).');
            return null;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D', $valor, $m) !== 1 || !checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $this->error($campo, 'Formato dd/MM/yyyy con una fecha valida (por ejemplo 07/10/2026).');
            return null;
        }
        return $m[3] . $m[2] . $m[1];
    }

    private function error(string $campo, string $mensaje): void
    {
        $this->errores[] = new Inconsistencia($campo, $mensaje);
    }
}
