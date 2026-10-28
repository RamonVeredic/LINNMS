<?php

declare(strict_types=1);

final class UpdateService
{
    public function __construct(private RuntimeBridge $bridge) {}

    public function upgrade(): array
    {
        return [
            'changed' => false,
            'image_managed' => true,
        ];
    }
}
