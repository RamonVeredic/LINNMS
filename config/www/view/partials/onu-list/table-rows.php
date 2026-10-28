<?php
$onus = $onus ?? [];
?>
<?php if ($onus === []): ?>
    <tr data-table-empty-row><td colspan="6" class="empty-cell">Tidak tersedia</td></tr>
<?php else: ?>
    <?php foreach ($onus as $onu):
        $viewUrl = public_page_url('view-onu', ['id' => (int)$onu['id']]);

        $baseState = effective_onu_state_key($onu['onu_state'] ?? null, $onu['rx_power'] ?? null, onu_loss_without_rx($onu));
        $isProblem = (int)($onu['is_problem'] ?? 0) === 1;
        $state = $isProblem ? 'problem' : $baseState;
        $operationalStateLabel = state_label($baseState);
        $stateLabel = $isProblem ? 'Bermasalah' : $operationalStateLabel;
        $filterStatus = $isProblem ? $operationalStateLabel . '|Bermasalah' : $operationalStateLabel;
        $stateDescription = $isProblem
            ? $operationalStateLabel . ' - ' . display_text($onu['problem_description'] ?? null, 'Bermasalah')
            : $stateLabel;
        $onuName = display_onu_name($onu['onu_name'] ?? null);
        $onuDetailFields = json_decode((string)($onu['detail_json'] ?? ''), true);
        $onuDetailFields = is_array($onuDetailFields) ? $onuDetailFields : [];
        $onuRxDisplay = $onu['rx_power'] ?? null;
        if (array_key_exists('rx_power_raw', $onuDetailFields)) {
            $onuRxDisplay = $onuDetailFields['rx_power_raw'];
        } elseif (($onu['source'] ?? '') === 'api'
            && array_key_exists('rx_power', (array)($onuDetailFields['device_response'] ?? []))) {
            $onuRxDisplay = $onuDetailFields['device_response']['rx_power'];
        }
        $onuRxSupported = !empty($onu['rx_power_supported']);
        $onuRxText = $onuRxSupported ? onu_rx_power_display($onuRxDisplay) : 'N/A';
        $onuLocation = display_onu_location($onu['onu_location'] ?? null, $onu['onu_name'] ?? null);
        $onuLocationSearch = onu_location_value($onuLocation);
        $ponLabel = onu_pon_label($onu['physical'] ?? null, $onu['onu_index'] ?? null);
        $ponFilterKey = (int)($onu['olt_device_id'] ?? 0) . '|' . onu_pon_filter_key($ponLabel);
        $isOnline = $baseState === 'online';

        $stateDotClass = match ($baseState) {
            'online' => 'success',
            'offline', 'powerdown' => 'danger',
            'loss' => 'neutral',
            default => 'neutral',
        };
        $rowStateClass = match ($state) {
            'loss' => ' onu-table-row-lost',
            'problem' => ' onu-table-row-problem',
            default => '',
        };
    ?>
        <tr class="onu-table-row<?= $isOnline ? '' : ' onu-table-row-faint' ?><?= $rowStateClass ?>"
            data-onu-source="<?= h((string)($onu['source'] ?? 'api')) ?>"
            data-onu-olt-id="<?= (int)($onu['olt_id'] ?? 0) ?>"
            data-onu-index="<?= h((string)($onu['onu_index'] ?? '')) ?>"
            data-onu-state="<?= h($state) ?>"
            data-filter-olt="<?= (int)($onu['olt_device_id'] ?? 0) ?>"
            data-filter-pon="<?= h($ponFilterKey) ?>"
            data-filter-status="<?= h($filterStatus) ?>"
            data-search-text="<?= h(implode(' ', [
                display_text($onu['olt_name'] ?? null),
                (string)($onu['onu_index'] ?? ''),
                $onuName,
                $onuLocationSearch,
                display_text($onu['onu_serial'] ?? null),
                display_text($onu['physical'] ?? null),
                $stateLabel,
            ])) ?>"
            data-href="<?= h($viewUrl) ?>" tabindex="0" role="link"
            aria-label="Lihat ONU <?= h($onuName) ?>"
            onclick="if (!event.target.closest('a,button,input,select,textarea,label')) window.location.href=this.dataset.href"
            onkeydown="if ((event.key==='Enter'||event.key===' ') && !event.target.closest('a,button,input,select,textarea,label')) { event.preventDefault(); window.location.href=this.dataset.href; }">
            <td>
                <span class="text-primary-bold"><?= h(display_text($onu['olt_name'] ?? null)) ?></span>
            </td>
            <td class="mono"> <span> <?= h((string)($onu['onu_index'] ?? '')) ?></span></td>
            <td class="onu-table-truncate onu-table-name-cell">
                <div class="onu-table-name-row">
                    <span class="status-dot <?= h($stateDotClass) ?>"
                       
                        role="img"
                        aria-label="Status: <?= h($stateDescription) ?>"
                        title="<?= h($stateDescription) ?>">
                        <span class="onu-visually-hidden"><?= h($stateDescription) ?></span>
                    </span>
                    <span><?= h($onuName) ?></span>
                </div>
            </td>
            <td class="onu-table-truncate"><span><?= h($onuLocation) ?></span></td>
            <td class="mono uppercase ">  <?= h(display_onu_serial($onu['onu_serial'] ?? null)) ?></td>
            <td>
                <span class="signal <?= h(power_class($onuRxDisplay)) ?>">
                    <i class="bi <?= h(power_icon_class($onuRxDisplay)) ?>" aria-hidden="true"></i>
                    <span><?= h($onuRxText) ?></span>
                </span>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
