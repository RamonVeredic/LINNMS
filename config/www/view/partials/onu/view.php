<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/format.php';

function onu_detail_view_model(
    array $readData,
    array $onuDetail,
    array $onuLive,
    mixed $onuSnmpDetail,
    array $onuRegisterLabels,
    bool $onuIsProblem = false,
    mixed $onuProblemDescription = null
): array {
$isSnmpDriver = (bool)($readData['is_snmp_driver'] ?? false);
$isApiSnmp = !$isSnmpDriver;
$onuDirectActionHandler = $isSnmpDriver
    ? 'onu_operation'
    : 'unsupported_onu_action';
$liveRow = [];
$snmpRow = is_array($onuSnmpDetail) ? $onuSnmpDetail : [];
$liveAuth = [];
$warnings = [];
$authMode = trim((string)($liveAuth['mode'] ?? $onuDetail['auth_mode'] ?? ''));
if ($authMode === '') $authMode = 'N/A';
$pick = static function (array $row, array $keys, mixed $fallback = null): mixed {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = $row[$key];
        if ($value === null) continue;
        if (is_string($value) && trim($value) === '') continue;
        return $value;
    }
    return $fallback;
};
$state = display_text($onuDetail['onu_state'] ?? null);
$serial = display_onu_serial($onuDetail['onu_serial'] ?? null);
$onuType = display_text($onuDetail['onu_type'] ?? null, 'N/A');

$registerLabels = is_array($onuRegisterLabels ?? null) && $onuRegisterLabels !== []
    ? $onuRegisterLabels
    : ['Pass' => 'Terdaftar', 'Undef' => 'Belum terdaftar', 'uncfg' => 'Uncfg', 'Deny' => 'Ditolak'];
$registerLabel = $registerLabels[$authMode] ?? 'N/A';
$registerConfirmed = in_array($authMode, ['Pass', 'Deny'], true);
$registerStatusLabel = match ($authMode) {
    'Pass', 'Deny' => 'Terdaftar',
    'Undef', 'uncfg' => 'Belum terdaftar',
    default => 'N/A',
};
$registerStatusClass = $registerConfirmed ? 'ok' : 'warn';
$onuServiceRow = is_array($readData['service_row'] ?? null) ? $readData['service_row'] : [];
$onuService = is_array($onuServiceRow['config'] ?? null) ? (array)$onuServiceRow['config'] : [];
if ($onuType === 'N/A') {
    $assignmentSerial = strtoupper(trim((string)($onuService['assignment_serial'] ?? '')));
    $inventorySerial = strtoupper(trim((string)($onuDetail['onu_serial'] ?? '')));
    if ($assignmentSerial !== '' && $assignmentSerial === $inventorySerial) {
        $onuType = display_text($onuService['onu_type'] ?? null, 'N/A');
    }
}
$onuServiceManual = (string)($onuServiceRow['source'] ?? '') === 'action';

$replacementOnus = is_array($readData['replacement_onus'] ?? null) ? $readData['replacement_onus'] : [];

$onuSnap = DataOnuView::snapshotFromRow($onuDetail);
$opticalValue = static function (string $field, mixed $fallback) use ($onuSnap, $onuDetail): mixed {
    if (array_key_exists($field . '_raw', $onuSnap['fields'])) return $onuSnap['fields'][$field . '_raw'];
    $response = $onuSnap['fields']['device_response'] ?? null;
    if (($onuDetail['source'] ?? '') === 'api' && is_array($response) && array_key_exists($field, $response)) {
        return $response[$field];
    }
    return $fallback;
};

$name = display_onu_name($onuDetail['onu_name'] ?? null);
$onu_rxPower = $opticalValue('rx_power', $pick($snmpRow, ['rx_power', 'rx'], $onuDetail['rx_power'] ?? null));
$onu_txPower = $opticalValue('tx_power', $pick($snmpRow, ['tx_power', 'tx'], $onuSnap['tx_power'] ?? null));

$olt_rxPower = $opticalValue('olt_rx_power', $pick($snmpRow, ['olt_rx_power'], $onuSnap['olt_rx_power'] ?? null));
$registerTime = $pick($snmpRow, ['register_time'], $onuSnap['register_time'] ?? null);
$deregisterTime = $pick($snmpRow, ['deregister_time'], $onuSnap['deregister_time'] ?? null);
$deregisterCount = $pick($snmpRow, ['deregister_count'], $onuSnap['deregister_count'] ?? null);
$temp = $opticalValue('temperature', $pick($snmpRow, ['temperature', 'temp'], $onuSnap['temperature'] ?? null));
$distance = $pick($snmpRow, ['distance'], $onuSnap['distance'] ?? null);
$pon = $pick($snmpRow, ['physical', 'pon'], $onuDetail['physical'] ?? null);
$portNumber = $onuSnap['port_count'] ?? null;
$onuDetailDefinition = is_array($readData['detail_definition'] ?? null) ? $readData['detail_definition'] : [];
$hiosoOnuDetail = (string)($onuDetailDefinition['ui_family'] ?? '') === 'hiosofamily';
if ($hiosoOnuDetail && $onuType === 'N/A') $onuType = '—';
$observedFields = (array)($onuDetailDefinition['onu_detail_fields'] ?? []);
foreach (['device_response', 'device_response_fast', 'device_response_middle', 'device_response_low', 'device_response_optical'] as $responseKey) {
    $observedResponse = $onuSnap['fields'][$responseKey] ?? null;
    if (!is_array($observedResponse)) continue;
    foreach (['rx_power', 'tx_power', 'olt_rx_power', 'temperature', 'distance', 'register_time', 'deregister_time', 'deregister_count', 'running_time', 'port_count'] as $field) {
        if (array_key_exists($field, $observedResponse)
            || array_key_exists($field . '_raw', $observedResponse)
            || array_key_exists($field, (array)($observedResponse['device_raw'] ?? []))) {
            $observedFields[] = $field;
        }
    }
}
$onuDetailDefinition['onu_detail_fields'] = array_values(array_unique($observedFields));
if ($hiosoOnuDetail) {
    $onuDetailDefinition['onu_detail_fields'] = array_values(array_diff($onuDetailDefinition['onu_detail_fields'], ['olt_rx_power']));
}
$runningTimeValue = $pick($snmpRow, ['running_time', 'online_duration'],
    $onuSnap['running_time'] ?? $onuSnap['online_duration'] ?? null);
$runningTime = format_onu_uptime($runningTimeValue);
$runningSeconds = null;
$offlineReason = trim((string)($onuSnap['offline_reason'] ?? ''));


$liveOk = false;
$dyingGasp = $liveRow['dying_gasp'] ?? ($liveOk ? ($onuLive['dying_gasp'] ?? null) : null);
$rtt = $snmpRow['rtt'] ?? null;
$location = display_onu_location($onuDetail['onu_location'] ?? null, $onuDetail['onu_name'] ?? null);
$onuRef = display_text($pick($snmpRow, ['onu_index', 'index'], $onuDetail['onu_index'] ?? null));
$valueDetail = DataOnuView::detailValues(
    is_array($onuDetailDefinition) ? $onuDetailDefinition : [],
    [
        'index' => $onuRef,
        'temperature' => $temp,
        'register_status' => $registerStatusLabel,
        'rx_power' => $onu_rxPower,
        'tx_power' => $onu_txPower,
        'olt_rx_power' => $olt_rxPower,
        'register_time' => $registerTime,
        'deregister_time' => $deregisterTime,
        'deregister_count' => $deregisterCount,
        'distance' => $distance,
        'port_count' => $portNumber,
        'running_time' => $runningTime,
    ]
);
foreach (['distance', 'port_count', 'running_time', 'register_time', 'deregister_time', 'deregister_count'] as $field) {
    $responseKeys = $field === 'port_count' || $field === 'running_time'
        ? ['device_response_low', 'device_response_middle', 'device_response']
        : ['device_response_middle', 'device_response'];
    foreach ($responseKeys as $responseKey) {
        $response = $onuSnap['fields'][$responseKey] ?? null;
        if (!is_array($response)) continue;
        if (array_key_exists($field . '_raw', $response)) {
            $value = $response[$field . '_raw'];
        } elseif (array_key_exists($field, $response)) {
            $value = $response[$field];
        } elseif (array_key_exists($field, (array)($response['device_raw'] ?? []))) {
            $value = $response['device_raw'][$field];
        } else {
            continue;
        }
        $valueDetail[$field] = DataOnuView::detailValues($onuDetailDefinition, [$field => $value])[$field];
        break;
    }
}
$optic = ['class' => 'neutral', 'label' => '—'];
$rxValue = onu_rx_power_number($onu_rxPower);
if ($rxValue !== null) {
    if ($rxValue > -8.0) {
        $optic = ['class' => 'strong', 'label' => 'Too Strong'];
    } elseif ($rxValue < -26.0) {
        $optic = ['class' => 'bad', 'label' => 'Too Low'];
    } elseif ($rxValue < -24.0) {
        $optic = ['class' => 'low', 'label' => 'Low'];
    } else {
        $optic = ['class' => 'good', 'label' => 'Good'];
    }
}
$pendingOperation = '';
$pendingSince = '';
$rebootPending = $pendingOperation === 'rebootOp';
$rebootElapsed = 0;
if ($rebootPending && $pendingSince !== '') {
    $pendingTs = strtotime($pendingSince);
    if ($pendingTs !== false) $rebootElapsed = max(0, time() - $pendingTs);
}

$effectiveStateKey = effective_onu_state_key(
    $onuDetail['onu_state'] ?? null,
    $onuDetail['rx_power'] ?? null,
    onu_loss_without_rx($onuDetail)
);
$displayStateKey = $effectiveStateKey;
$operationalStateLabel = state_label($effectiveStateKey);
$displayStateClass = match ($displayStateKey) {
    'online' => 'is-up',
    'offline', 'powerdown' => 'is-down',
    'loss' => 'is-neutral',
    'problem' => 'is-problem',
    default => 'is-neutral',
};
$displayDotClass = match ($displayStateKey) {
    'online' => 'success',
    'offline', 'powerdown', 'problem' => 'danger',
    default => 'neutral',
};
$authIndicatorClass = match ($authMode) {
    'Pass' => 'ok',
    'Deny' => 'bad',
    'Undef' => 'warn',
    default => 'neutral',
};
$isInactiveOnu = $displayStateKey !== 'online';
$stateAccentClass = match ($displayStateKey) {
    'online' => 'accent-green',
    'offline', 'powerdown' => 'accent-down',
    'loss' => 'accent-lost',
    'problem' => 'accent-red',
    default => 'accent-blue',
};

$oltName = display_text($onuDetail['olt_name'] ?? null);
$oltTypeLabel = (string)($readData['olt_type_label'] ?? '');

$formatNumeric = static function (mixed $value, int $decimals = 2, string $suffix = ''): string {
    if ($value === null || $value === '' || !is_numeric($value)) return 'N/A';
    return number_format((float)$value, $decimals) . $suffix;
};
    $oltVlanIds = array_values(array_filter(
        (array)($readData['vlan_ids'] ?? []),
        static fn($id): bool => (int)$id >= 1 && (int)$id <= 4094
    ));
    return [
        'ok' => true,
        'id' => (int)($onuDetail['id'] ?? 0),
        'name' => $name,
        'serial' => $serial,
        'onuType' => $onuType,
        'equipmentId' => display_text($onuSnap['equipment_id'] ?? null, $hiosoOnuDetail ? '—' : '-'),
        'oltVlanIds' => $oltVlanIds,
        'onuTypeChoices' => is_array($readData['available_onu_types'] ?? null) ? $readData['available_onu_types'] : [],
        'olt_device_id' => (int)($onuDetail['olt_device_id'] ?? 0),
        'type' => (string)($readData['driver_id'] ?? ''),
        'driver_id' => (string)($readData['driver_id'] ?? ''),
        'olt_row_id' => (int)($onuDetail['olt_row_id'] ?? 0),
        'oltName' => $oltName,
        'oltTypeLabel' => $oltTypeLabel,
        'location' => $location,
        'onuRef' => $onuRef,
        'authMode' => $authMode,
        'registerLabels' => $registerLabels,
        'registerLabel' => $registerLabel,
        'registerStatusLabel' => $registerStatusLabel,
        'registerStatusClass' => $registerStatusClass,
        'authIndicatorClass' => $authIndicatorClass,
        'replacementOnus' => $replacementOnus,
        'displayStateClass' => $displayStateClass,
        'displayDotClass' => $displayDotClass,
        'operationalStateLabel' => $operationalStateLabel,
        'stateAccentClass' => $stateAccentClass,
        'isInactiveOnu' => $isInactiveOnu,
        'onuIsProblem' => $onuIsProblem,
        'onuProblemDescription' => $onuProblemDescription,
        'rebootPending' => $rebootPending,
        'rebootElapsed' => $rebootElapsed,
        'optic' => $optic,
        'rx' => $onu_rxPower,
        'tx' => $onu_txPower,
        'oltRx' => $olt_rxPower,
        'temp' => $temp,
        'distance' => $distance,
        'portNumber' => $portNumber,
        'runningTime' => $runningTime,
        'registerTime' => $registerTime,
        'deregisterTime' => $deregisterTime,
        'deregisterCount' => $deregisterCount,
        'warnings' => $warnings,
        'formatNumeric' => $formatNumeric,
        'valueDetail' => $valueDetail,

        'onuService' => $onuService,
        'onuServiceManual' => $onuServiceManual,
        'historyEntries' => onu_history_entries($onuDetail, $readData),
    ];
}


function onu_history_entries(array $onuDetail, array $readData): array
{
$onuHistory = is_array($readData['state_history'] ?? null) ? $readData['state_history'] : [];
$onuSignalHistory = is_array($readData['signal_history'] ?? null) ? $readData['signal_history'] : [];
$rxProblemThreshold = (float)($readData['rx_problem_threshold_dbm'] ?? LinnmsRepository::ONU_RX_PROBLEM_DEFAULT_DBM);

$lossWithoutRx = onu_loss_without_rx($onuDetail);
$formatDuration = static function (int $seconds): string {
    if ($seconds < 60) return $seconds . ' dtk';
    if ($seconds < 3600) return intdiv($seconds, 60) . ' mnt';
    if ($seconds < 86400) return intdiv($seconds, 3600) . ' jam ' . intdiv($seconds % 3600, 60) . ' mnt';
    return intdiv($seconds, 86400) . ' hari ' . intdiv($seconds % 86400, 3600) . ' jam';
};
$historyEntries = [];
foreach ($onuHistory as $historyIndex => $entry) {
    $stateAt = strtotime((string)($entry['at'] ?? ''));
    if ($stateAt === false) continue;
    $stateKey = effective_onu_state_key($entry['state'] ?? null, null, $lossWithoutRx);
    $label = match ($stateKey) {
        'online' => 'Online',
        'loss' => 'Lost',
        'powerdown' => 'DyingGasp',
        'offline' => 'Offline',
        default => null,
    };
    if ($label === null) continue;
    $durationSeconds = null;
    $isOngoing = false;
    if ($historyIndex === 0) {
        $currentStateKey = effective_onu_state_key($onuDetail['onu_state'] ?? null, null, $lossWithoutRx);
        if ($stateKey === $currentStateKey) {
            $durationSeconds = max(0, time() - $stateAt);
            $isOngoing = true;
        }
    } else {
        $nextStateAt = strtotime((string)($onuHistory[$historyIndex - 1]['at'] ?? ''));
        if ($nextStateAt !== false) $durationSeconds = max(0, $nextStateAt - $stateAt);
    }
    $historyEntries[] = [
        'at' => $stateAt,
        'label' => $label,
        'tone' => $stateKey === 'online' ? 'ok' : 'bad',
        'duration' => $durationSeconds !== null ? $formatDuration($durationSeconds) : null,
        'ongoing' => $isOngoing,
        'label_old' => '',
        'kind' => 'status',
    ];
}
foreach ($onuSignalHistory as $entry) {
    $signalAt = strtotime((string)($entry['created_at'] ?? ''));
    $value = trim((string)($entry['new_value'] ?? ''));
    $rxHistoryValue = onu_rx_power_number($value);
    if ($signalAt === false || $rxHistoryValue === null) continue;
    $problem = $rxHistoryValue <= -abs($rxProblemThreshold);
    $historyEntries[] = [
        'at' => $signalAt,
        'label' => $value . ' dBm',
        'label_old' => '',
        'tone' => $problem ? 'bad' : 'ok',
        'duration' => null,
        'kind' => 'rx',
    ];
}
usort($historyEntries, static fn(array $left, array $right): int => $right['at'] <=> $left['at']);
    return $historyEntries;
}
