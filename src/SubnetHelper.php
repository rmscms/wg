<?php

declare(strict_types=1);

namespace WgPanel;

use RuntimeException;

final class SubnetHelper
{
    private readonly int $network;
    private readonly int $broadcast;
    private readonly int $serverIp;

    public function __construct(
        string $subnetCidr,
        string $serverIp,
    ) {
        [$networkIp, $prefixStr] = explode('/', $subnetCidr, 2);
        $prefix = (int) $prefixStr;

        if ($prefix < 8 || $prefix > 30) {
            throw new RuntimeException('Subnet prefix must be between /8 and /30.');
        }

        $this->network = $this->networkAddress($networkIp, $prefix);
        $this->broadcast = $this->network | $this->hostMask($prefix);
        $this->serverIp = $this->ipToLong($serverIp);

        if ($this->serverIp < $this->network || $this->serverIp > $this->broadcast) {
            throw new RuntimeException('Server IP is outside the configured subnet.');
        }
    }

    public static function fromConfig(array $wireguardConfig): self
    {
        return new self(
            $wireguardConfig['subnet'],
            $wireguardConfig['server_ip'],
        );
    }

    public function allocateNext(array $usedIps): string
    {
        $used = [];

        foreach ($usedIps as $ip) {
            $used[$this->ipToLong((string) $ip)] = true;
        }

        for ($candidate = $this->network + 1; $candidate < $this->broadcast; $candidate++) {
            if ($candidate === $this->serverIp) {
                continue;
            }

            if (!isset($used[$candidate])) {
                return $this->longToIp($candidate);
            }
        }

        throw new RuntimeException('No available IP addresses in subnet.');
    }

    private function networkAddress(string $ip, int $prefix): int
    {
        return $this->ipToLong($ip) & $this->networkMask($prefix);
    }

    private function networkMask(int $prefix): int
    {
        if ($prefix === 0) {
            return 0;
        }

        return (int) (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    }

    private function hostMask(int $prefix): int
    {
        return $this->networkMask($prefix) ^ 0xFFFFFFFF;
    }

    private function ipToLong(string $ip): int
    {
        $packed = inet_pton(trim($ip));

        if ($packed === false || strlen($packed) !== 4) {
            throw new RuntimeException("Invalid IPv4 address: {$ip}");
        }

        $parts = unpack('N', $packed);

        return $parts[1];
    }

    private function longToIp(int $long): string
    {
        $ip = inet_ntop(pack('N', $long));

        if ($ip === false) {
            throw new RuntimeException('Failed to convert IP address.');
        }

        return $ip;
    }
}
