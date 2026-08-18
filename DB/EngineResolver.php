<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB;

use Magento\Framework\App\DeploymentConfig;

final class EngineResolver
{
    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    public function isPostgres(): bool
    {
        $default = $this->deploymentConfig->get('db/connection/default');
        return is_array($default) && DbEngine::isPostgres($default);
    }
}
