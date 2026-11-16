<?php

namespace Modules\Servers\EsapanelLicense;

/**
 * One answer from the Esapanel License Server.
 *
 * status 0 means the server was not reached at all (network, TLS, timeout).
 */
final class LicenseServerResponse
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?array $data = null,
        public readonly string $error = '',
        public readonly array $meta = [],
    ) {}
}
