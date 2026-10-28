<?php $terminalSupported = !empty(($ovData['capabilities'] ?? [])['terminal']); ?>
<span data-tip="<?= $terminalSupported ? 'Buka terminal OLT' : 'Terminal belum tersedia untuk perangkat ini' ?>" data-tip-pos="bottom"><button class="button secondary olt-terminal-button" type="button" data-olt-terminal-open
    aria-label="<?= $terminalSupported ? 'Buka terminal OLT' : 'Terminal belum tersedia untuk perangkat ini' ?>"
    <?= $terminalSupported ? '' : 'disabled' ?>><i class="bi bi-terminal" aria-hidden="true"></i></button></span>
