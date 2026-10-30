<?php
$oltPonIsZte = $oltViewFamily === 'zte';
$oltPonIsExternal = $oltViewFamily === 'external';
$oltPonUsesCardLayout = $oltPonIsZte || $oltPonIsExternal;
$oltPonStatePreferAdmin = $oltPonUsesCardLayout;
$oltPonHealthFallback = $oltPonUsesCardLayout ? [] : $oltSystemHealth;
$oltPonHealthTelemetry = !$oltPonUsesCardLayout;
$oltPonCardHealthFormatter = $oltPonUsesCardLayout ? $oltNumber : $oltPonHealthFormatter;
$oltPonFieldPrefix = $oltPonIsZte ? 'ztePonDescription-' : ($oltPonIsExternal ? 'externalPonDescription-' : 'hiosoPonDescription-');
$oltPonModalPartial = $oltPonIsZte ? 'zte-modal.php' : ($oltPonIsExternal ? 'external-modal.php' : 'hioso-modal.php');
?>
        <div id="olt-panel-pon" role="tabpanel" aria-labelledby="olt-tab-pon" data-olt-panel="pon">
            <div id="olt-pon-config"><div id="olt-pon-cards" class="olt-card-list">
            <?php $oltFocusTaken = false; $oltLegacyUsed = []; ?>
            <?php foreach ($oltGroups as $slot => $group): ?>
                <?php
                $card = $group['card'];
                $ponStateViewModel = OltDetailViewModel::ponPortStates((array)$group['ports'], (array)$card, $oltPonStatePreferAdmin);
                $counts = $ponStateViewModel['counts'];
                $portStates = $ponStateViewModel['states'];
                $totalPorts = $ponStateViewModel['total'];
                $expectedPorts = $ponStateViewModel['expected'];
                $incomplete = $ponStateViewModel['incomplete'];
                ?>
                <article class="olt-card<?= $oltPonUsesCardLayout ? '' : ' olt-interface-group' ?>">
                    <?php if ($oltPonUsesCardLayout): ?>
                        <div class="olt-card-title">
                        <div class="olt-card-heading">
                            <h3><i class="bi bi-pc-horizontal" aria-hidden="true"></i> Card <?= h($slot > 0 ? (string)$slot : 'N/A') ?> · <?= h($oltText($card['model'] ?? null)) ?></h3>
                        </div>
                        <?php if (trim((string)($card['label'] ?? '')) !== ''): ?><p class="olt-card-label"><?= h($card['label']) ?></p><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="olt-card-heading">
                            <h3><i class="bi bi-ethernet" aria-hidden="true"></i> Port PON</h3>
                        </div>
                    <?php endif; ?>
                    <div class="olt-card-layout">
                        <?php $oltHealthCard = (array)$card; $oltHealthFallback = $oltPonHealthFallback; $oltHealthSlot = (int)$slot; $oltHealthTelemetry = $oltPonHealthTelemetry; $oltHealthFormatter = $oltPonCardHealthFormatter; require __DIR__ . '/card-health.php'; ?>
                        <div class="olt-card-ports">
                            <?php
                            $ponSummaryTotalText = (string)$oltNumber($expectedPorts ?? ($totalPorts > 0 ? $totalPorts : null));
                            $ponSummaryCounts = is_array($counts) ? $counts : [];
                            $ponSummaryIncomplete = !empty($incomplete);
                            ?>
                            <div class="olt-port-counts">
                                <div><span>Total PONs</span><strong><?= h($ponSummaryTotalText) ?></strong></div>
                                <?php foreach (['Up', 'Down', 'Shutdown'] as $ponSummaryLabel): ?>
                                    <div><span><?= h($ponSummaryLabel) ?></span><strong><?= h($ponSummaryIncomplete ? 'N/A' : (string)($ponSummaryCounts[$ponSummaryLabel] ?? 0)) ?></strong></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="olt-pon-grid olt-port-grid">
                            <?php foreach ($group['ports'] as $ref => $port): ?>
                                <?php
                                $ponIdentity = OltDetailViewModel::ponPortIdentity((array)$port, (string)$ref);
                                $ponRef = $ponIdentity['ref'];
                                $ponDisplayLabel = $oltPonUsesCardLayout ? $ponIdentity['label'] : 'PON ' . $ponRef;
                                $ponCardTitle = $oltPonUsesCardLayout
                                    ? 'Card ' . ($slot > 0 ? (string)$slot : 'N/A') . ' - ' . $oltText($card['model'] ?? null)
                                    : 'PON ' . $ponRef;
                                $ponNumber = $ponIdentity['number'];
                                $ponKey = $ponIdentity['key'];
                                $ponModalId = $ponIdentity['modal_id'];
                                $ponFieldId = $oltPonFieldPrefix . $ponKey;
                                $ponDescription = trim((string)($port['description'] ?? ''));
                                $ponDescriptionAction = 'update_snmp_olt_port_description';
                                $ponOpticalRows = $oltPonOpticalRows[$ref] ?? [];
                                $ponTxPower = is_numeric($port['tx_power'] ?? null)
                                    ? (float)$port['tx_power']
                                    : (is_numeric($oltTx[$ref] ?? null) ? (float)$oltTx[$ref] : null);
                                $ponConfigFields = olt_pon_port_config_fields();
                                $ponConfigValues = OltDetailViewModel::ponPortConfigValues((array)$port, true);
                                $focusRef = $oltRef((string)($_GET['pon_ref'] ?? ''));
                                $ponIsFocused = $focusRef !== '' ? $focusRef === $ref
                                    : (!$oltFocusTaken && $ponNumber > 0 && $ponNumber === (int)($oltPonFocusNumber ?? 0));
                                if ($ponIsFocused) $oltFocusTaken = true;
                                $state = $portStates[$ref];
                                $stateClass = $ponStateViewModel['classes'][$ref] ?? 'is-unknown';
                                ?>
                                <article class="config-card olt-pon-card <?= h($stateClass) ?><?= $ponIsFocused ? ' is-focused' : '' ?>" id="pon-<?= h($ponKey) ?>">
                                    <?php if ($ponNumber > 0 && !isset($oltLegacyUsed[$ponNumber])): $oltLegacyUsed[$ponNumber] = true; ?><span class="olt-pon-anchor" id="pon-<?= $ponNumber ?>" aria-hidden="true"></span><?php endif; ?>
                                    <button type="button" class="olt-pon-card-head" title="<?= h($ponDisplayLabel . ' · ' . $state . ($ponDescription !== '' ? ' · ' . $ponDescription : '')) ?>" data-olt-pon-modal-open="<?= h($ponModalId) ?>" aria-haspopup="dialog" aria-controls="<?= h($ponModalId) ?>">
                                            <span class="olt-port-icon"><i class="bi bi-ethernet" aria-hidden="true"></i><span class="olt-port-badge"><?= h($ponNumber > 0 ? (string)$ponNumber : '?') ?></span></span>
                                            <span class="olt-port-path"><?= h($ponDisplayLabel) ?></span>
                                    </button>
                                </article>
                                <?php require __DIR__ . '/' . $oltPonModalPartial; ?>
                            <?php endforeach; ?>
                            <?php if ($group['ports'] === []): ?><div class="olt-empty"><strong>N/A</strong><span>Data port PON belum tersedia.</span></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php foreach (OltDetailViewModel::otherCards($oltPonCards, $oltPonIsZte ? $oltUplinkBySlot : []) as $oltOtherRow): ?>
                <?php
                $oltOtherCard = $oltOtherRow['card'];
                $oltOtherRole = $oltOtherRow['role'];
                $oltOtherSlot = $oltOtherRow['slot'];
                $oltOtherModel = $oltOtherRow['model'];
                $oltOtherLabel = $oltOtherRow['label'];
                $oltOtherHw = $oltOtherRow['hardware'];
                $oltOtherSw = $oltOtherRow['software'];
                $oltOtherPresent = $oltOtherRow['present'];
                $oltOtherPorts = $oltOtherRow['ports'];
                $oltOtherHasPortLayout = $oltOtherRow['has_port_layout'];
                $oltOtherPortStates = $oltOtherRow['port_states'];
                ?>
                <article class="olt-card olt-card-static<?= $oltOtherHasPortLayout ? ' olt-card-with-ports' : '' ?><?= $oltOtherPresent ? '' : ' is-offline' ?>">
                    <div class="olt-card-heading">
                        <?php if ($oltPonUsesCardLayout): ?>
                            <h3><i class="bi bi-pc-horizontal" aria-hidden="true"></i> Card <?= h($oltOtherSlot > 0 ? (string)$oltOtherSlot : 'N/A') ?> · <?= h($oltText($oltOtherModel !== '' ? $oltOtherModel : $oltOtherLabel)) ?></h3>
                        <?php else: ?>
                            <h3><i class="bi bi-ethernet" aria-hidden="true"></i> <?= h($oltOtherLabel !== '' ? $oltOtherLabel : 'Port jaringan') ?></h3>
                        <?php endif; ?>
                    </div>
                    <div class="olt-card-body<?= $oltOtherHasPortLayout ? ' olt-card-layout' : '' ?>">
                    <?php $oltHealthCard = $oltOtherCard; $oltHealthFallback = $oltPonHealthFallback; $oltHealthSlot = $oltOtherSlot; $oltHealthTelemetry = $oltPonHealthTelemetry; $oltHealthStatic = true; $oltHealthFormatter = $oltPonCardHealthFormatter; require __DIR__ . '/card-health.php'; ?>
                    <?php if ($oltOtherPorts !== []): ?>
                        <div class="olt-card-ports olt-card-ports-static">
                            <div class="olt-port-counts">
                                <div><span>Total Ports</span><strong><?= h((string)count($oltOtherPorts)) ?></strong></div>
                                <?php foreach (['Up', 'Down', 'Shutdown'] as $oltStateLabel): ?>
                                    <div><span><?= h($oltStateLabel) ?></span><strong><?= h((string)$oltOtherPortStates[$oltStateLabel]) ?></strong></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="olt-pon-grid olt-port-grid">
                                <?php foreach ($oltOtherPorts as $oltPortRow): ?>
                                    <?php
                                    $oltPort = OltDetailViewModel::otherCardPort($oltPortRow);
                                    $oltPortName = $oltPort['name'];
                                    $oltPortState = $oltPort['state'];
                                    $oltPortClass = $oltPort['class'];
                                    $oltPortNo = $oltPort['number'];
                                    $oltPortSpeed = $oltPort['speed'];
                                    $oltPortEnable = $oltPort['enable'];
                                    $oltIsUplinkPort = $oltPonIsZte && $oltPortName !== '' && array_key_exists($oltPortName, $oltUplinkState);
                                    ?>
                                    <?php if ($oltPonUsesCardLayout): ?>
                                        <article class="config-card olt-pon-card <?= h($oltPortClass) ?>" title="Port <?= h($oltPortName) ?> — <?= h($oltPortState) ?>"<?= ($oltPonIsZte && ($oltOtherRole === 'uplink' || $oltIsUplinkPort)) ? ' data-olt-uplink-open="' . h($oltPortName) . '" data-olt-uplink-modal-id="oltUplinkModal" role="button" tabindex="0"' : '' ?>>
                                            <div class="olt-pon-card-head">
                                                <span class="olt-port-icon"><i class="bi bi-ethernet" aria-hidden="true"></i><span class="olt-port-badge"><?= h($oltPortNo !== null ? (string)$oltPortNo : '?') ?></span></span>
                                                <span class="olt-port-speed"><?= h($oltPortSpeed !== '' ? $oltPortSpeed : 'N/A') ?></span>
                                                <span class="olt-port-path"><?= h($oltPortName !== '' ? $oltPortName : 'N/A') ?></span>
                                            </div>
                                        </article>
                                    <?php else: ?>
                                        <?php $oltNetworkModalId = 'olt-network-modal-' . (int)$oltOtherSlot . '-' . preg_replace('/[^a-z0-9]+/i', '-', $oltPortName); ?>
                                        <button type="button" class="config-card olt-pon-card <?= h($oltPortClass) ?>" title="Port <?= h($oltPortName) ?> — <?= h($oltPortState) ?>"<?= $oltOtherRole === 'uplink' ? ' data-olt-pon-modal-open="' . h($oltNetworkModalId) . '" aria-haspopup="dialog"' : '' ?>>
                                            <div class="olt-pon-card-head">
                                                <span class="olt-port-icon"><i class="bi bi-ethernet" aria-hidden="true"></i><span class="olt-port-badge"><?= h($oltPortNo !== null ? (string)$oltPortNo : '?') ?></span></span>
                                                <span class="olt-port-speed"><?= h($oltPortSpeed !== '' ? $oltPortSpeed : 'N/A') ?></span>
                                                <span class="olt-port-path"><?= h($oltPortName !== '' ? $oltPortName : 'N/A') ?></span>
                                            </div>
                                        </button>
                                        <?php if ($oltOtherRole === 'uplink'): ?>
                                        <div id="<?= h($oltNetworkModalId) ?>" class="modal olt-pon-modal" data-olt-pon-modal hidden aria-hidden="true">
                                            <div class="modal-card olt-pon-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?= h($oltNetworkModalId) ?>-title">
                                                <div class="olt-pon-modal-heading modal-header">
                                                    <div class="olt-pon-modal-title">
                                                        <h2 id="<?= h($oltNetworkModalId) ?>-title"><?= h($oltPortName !== '' ? $oltPortName : 'N/A') ?></h2>
                                                        <span class="olt-pon-link <?= h($oltPortClass) ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i> <?= h($oltPortState) ?></span>
                                                    </div>
                                                    <button class="icon-button modal-close-button" type="button" data-olt-pon-modal-close aria-label="Tutup modal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                                </div>
                                                <div class="olt-pon-modal-section">
                                                    <dl class="olt-optical-details">
                                                        <div><dt>Port</dt><dd><?= h($oltPortName !== '' ? $oltPortName : 'N/A') ?></dd></div>
                                                        <div><dt>Status</dt><dd><?= h($oltPortState) ?></dd></div>
                                                        <div><dt>Enable</dt><dd><?= h($oltPortEnable !== '' ? $oltPortEnable : 'N/A') ?></dd></div>
                                                        <div><dt>Speed</dt><dd><?= h(((string)($oltPortRow['speed_mbps'] ?? '')) !== '' ? (string)$oltPortRow['speed_mbps'] . ' Mbps' : 'N/A') ?></dd></div>
                                                    </dl>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif ($oltOtherHasPortLayout): ?>
                        <div class="olt-card-ports olt-empty"><strong>N/A</strong><span>Data port belum tersedia.</span></div>
                    <?php endif; ?>
                    </div>
                    <p class="olt-card-label"><?= h('HW ' . ($oltOtherHw !== '' ? $oltOtherHw : 'N/A') . ' · SW ' . ($oltOtherSw !== '' ? $oltOtherSw : 'N/A')) ?></p>
                </article>
            <?php endforeach; ?>
            </div></div>
        </div>
