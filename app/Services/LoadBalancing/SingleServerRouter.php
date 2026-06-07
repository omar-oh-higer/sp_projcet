<?php

namespace App\Services\LoadBalancing;

/**
 * Before improvement (vertical scaling): every request goes to one fixed server.
 * No load balancer — simulates a single-server bottleneck under spike traffic.
 */
class SingleServerRouter
{
    public function target(): string
    {
        return (string) config('load_balancing.single_target', 'server-1');
    }
}
