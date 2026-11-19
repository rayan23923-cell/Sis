<?php

namespace App\Services\Payments;

use App\Services\Payments\Gateways\FibGateway;
use App\Services\Payments\Gateways\QiCardGateway;
use App\Services\Payments\Gateways\SandboxGateway;
use App\Services\Payments\Gateways\ZainCashGateway;

/** The gateways the system knows, and which of them are switched on. */
class Gateways
{
    /** @var array<string, Gateway> */
    private array $all;

    public function __construct()
    {
        $this->all = [];
        foreach ([new QiCardGateway, new ZainCashGateway, new FibGateway, new SandboxGateway] as $g) {
            $this->all[$g->key()] = $g;
        }
    }

    public function get(string $key): Gateway
    {
        return $this->all[$key] ?? throw new GatewayException('بوابة دفع غير معروفة.');
    }

    /** @return array<string, string> key => label of the gateways that can take payments now */
    public function available(): array
    {
        $out = [];
        foreach ($this->all as $k => $g) {
            if ($g->enabled()) {
                $out[$k] = $g->label();
            }
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, enabled: bool}> */
    public function overview(): array
    {
        return array_values(array_map(fn (Gateway $g) => ['key' => $g->key(), 'label' => $g->label(), 'enabled' => $g->enabled()], $this->all));
    }
}
