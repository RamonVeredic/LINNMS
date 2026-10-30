<?php if (is_array($oltDetail ?? null)): ?>
<dialog class="olt-terminal-dialog" data-olt-terminal-dialog
    data-terminal-url="<?= h(public_app_url('terminal.php')) ?>"
    data-terminal-ref="<?= h(public_resource_token('olt', (int)$oltDetail['id'])) ?>"
    data-terminal-csrf="<?= h(csrf_token()) ?>" aria-labelledby="oltTerminalTitle">
    <div class="olt-terminal-heading modal-header">
        <div class="olt-terminal-identity">
            <span class="olt-terminal-mark"><i class="bi bi-terminal" aria-hidden="true"></i></span>
            <div><span class="olt-terminal-eyebrow">DEVICE CONSOLE</span><h2 id="oltTerminalTitle"><?= h((string)($oltDetail['name'] ?? 'OLT')) ?></h2></div>
        </div>
        <div class="olt-terminal-actions">
            <button class="icon-button" type="button" data-olt-terminal-expand aria-label="Perbesar terminal" title="Perbesar terminal" aria-pressed="false"><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i></button>
            <button class="icon-button modal-close-button" type="button" data-olt-terminal-close aria-label="Tutup terminal" title="Tutup terminal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="olt-terminal-toolbar"><p class="olt-terminal-status" data-olt-terminal-status role="status" aria-live="polite"></p><span class="olt-terminal-protocol">CLI SESSION</span></div>
    <div class="olt-terminal-screen" data-olt-terminal-screen aria-label="Terminal OLT"></div>
    <div class="olt-terminal-footer"><span><i class="bi bi-keyboard" aria-hidden="true"></i> Ketik perintah langsung di terminal</span><span>Scroll untuk melihat riwayat</span></div>
</dialog>
<?php endif; ?>
