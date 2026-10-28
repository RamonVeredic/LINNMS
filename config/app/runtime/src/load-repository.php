<?php

declare(strict_types=1);



$traitDir = __DIR__ . DIRECTORY_SEPARATOR . 'RepositoryTraits';
$traitFiles = glob($traitDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
sort($traitFiles);
foreach ($traitFiles as $traitFile) {
    require_once $traitFile;
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LinnmsRepository.php';
