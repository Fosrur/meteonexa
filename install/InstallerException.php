<?php
declare(strict_types=1);

final class MeteoNexaInstallerException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $translationKey = 'install.error.generic',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}

function install_fail(
    string $errorCode,
    string $translationKey = 'install.error.generic',
    array $context = [],
    ?Throwable $previous = null,
): never {
    throw new MeteoNexaInstallerException($errorCode, $translationKey, $context, $previous);
}
