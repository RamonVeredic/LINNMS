<?php

declare(strict_types=1);

function monitoring_row_uses_handler(array $row, string $handler): bool
{
    $definition = monitoring_driver_definition(device_driver_type($row));
    return is_array($definition) && (string)($definition['handler'] ?? '') === $handler;
}
