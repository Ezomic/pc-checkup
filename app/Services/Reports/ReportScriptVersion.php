<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\CheckupScript;

final class ReportScriptVersion
{
    /**
     * @return array{version: string|null, current: string, outdated: bool, ranOutdated: bool}|null
     */
    public function for(string $markdown): ?array
    {
        $parsed = (new ReportParser)->parse($markdown);
        $script = CheckupScript::forOs($parsed->os);

        if ($script === null) {
            return null;
        }

        $current = $script->version();

        return [
            'version' => $parsed->scriptVersion,
            'current' => $current,
            'outdated' => $parsed->scriptVersion !== $current,
            'ranOutdated' => $parsed->updateCheck === 'outdated, continued',
        ];
    }
}
