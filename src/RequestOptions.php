<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Opciones de una sola llamada. Se pasan como ultimo argumento (`opciones:`) de cualquier metodo
 * del cliente que llama al API y solo afectan a esa llamada.
 *
 * - `timeout`: tiempo maximo de cada intento, en segundos. Reemplaza al `timeout` del cliente en esta
 *   llamada. Se aplica con el transporte cURL por defecto (o un transporte que implemente
 *   {@see Transport\TimeoutOverrideTransportInterface}); con otro transporte se ignora.
 * - `totalTimeout`: tope de toda la llamada, incluidos los reintentos, en segundos. Reemplaza a
 *   `totalTimeoutSeconds` del cliente en esta llamada.
 * - `correlationId`: se envia en la cabecera `X-Correlation-Id`. De 1 a 64 caracteres
 *   `A-Z a-z 0-9 . _ -`. El API lo devuelve y el SDK lo expone en `EmisionResultado::$correlationId`
 *   y en las excepciones (`idSeguimiento` o `correlationId`).
 * - `reintentos`: `false` desactiva los reintentos solo en esta llamada.
 * - `additionalInfo`: valor de la cabecera `X-Additional-Info` solo en esta llamada. Reemplaza al
 *   `additionalInfo` del cliente. Misma validacion que en {@see EcuafactClientOptions}.
 *
 * PHP no tiene cancelacion cooperativa: para acotar una llamada usa `timeout` o `totalTimeout`.
 *
 * Un valor invalido lanza {@see EcuafactConfigurationException}.
 */
final class RequestOptions
{
    private const CORRELATION_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** Cabecera X-Additional-Info de esta llamada; null usa la del cliente. */
    public readonly ?string $additionalInfo;

    public function __construct(
        public readonly ?float $timeout = null,
        public readonly ?float $totalTimeout = null,
        public readonly ?string $correlationId = null,
        public readonly bool $reintentos = true,
        ?string $additionalInfo = null,
    ) {
        // Cadena vacia: no se envia la cabecera en esta llamada (null usa la del cliente).
        $this->additionalInfo = $additionalInfo === '' ? '' : EcuafactClientOptions::validarAdditionalInfo($additionalInfo);
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new EcuafactConfigurationException('timeout debe ser mayor que 0 segundos o null.');
        }
        if ($totalTimeout !== null && (!is_finite($totalTimeout) || $totalTimeout <= 0)) {
            throw new EcuafactConfigurationException('totalTimeout debe ser mayor que 0 segundos o null.');
        }
        if ($correlationId !== null && preg_match(self::CORRELATION_PATTERN, $correlationId) !== 1) {
            throw new EcuafactConfigurationException(
                'correlationId: de 1 a 64 caracteres, solo letras, digitos y . _ -.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        if ($this->additionalInfo !== null && $this->additionalInfo !== '') {
            $info['additionalInfo'] = '***';
        }
        return $info;
    }
}
