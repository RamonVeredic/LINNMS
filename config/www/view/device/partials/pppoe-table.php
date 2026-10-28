        <section class="table-panel mikrotik-pppoe-table" data-interface-list>
            <div class="table-toolbar device-interface-toolbar">
                <div><strong class="dashboard-table-title">PPPoE</strong><small class="dashboard-table-subtitle"><?= count($pppoeInterfaces) ?> sesi interface PPPoE</small></div><label class="interface-list-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-interface-search placeholder="Cari..." autocomplete="off" aria-label="Cari ..."></label>
            </div>
            <div class="table-wrap">
                <table>
                    <colgroup>
                        <col class="interface-name-column">
                        <col class="interface-type-column">
                        <col class="interface-rate-column">
                        <col class="interface-rate-column">
                        <col class="interface-record-column">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Type</th>
                            <th>RX</th>
                            <th>TX</th>
                            <th>Catat</th>
                        </tr>
                    </thead>
                    <tbody data-interface-list-rows>
                        <?php foreach ($pppoeInterfaces as $interface): $adminDisabled = strtolower((string)($interface['admin_status'] ?? '')) !== 'up';
                            $interfaceName = (string)($interface['name'] ?? '');
                            $ifIndex = (int)($interface['if_index'] ?? 0);
                            $interfaceRate=is_array($interfaceRates[(string)$ifIndex]??null)?$interfaceRates[(string)$ifIndex]:[];
                            $isInterfaceUp = strtolower((string)($interface['oper_status'] ?? '')) === 'up';
                            $pppoeDisplayName = $displayPppoeName($interfaceName); ?><tr class="<?= $adminDisabled ? 'is-admin-disabled' : '' ?>" data-interface-list-row data-interface-search-name="<?= h(mb_strtolower($pppoeDisplayName)) ?>" data-interface-rate-row data-if-index="<?= $ifIndex ?>" <?= $adminDisabled ? ' aria-disabled="true"' : '' ?>>
                                <td><span><?= h($pppoeDisplayName) ?></span></td>
                                <td><span class="interface-type-with-status"><span class="status-dot <?= $isInterfaceUp ? 'success' : 'danger' ?>" role="img" aria-label="<?= $isInterfaceUp ? 'Up' : 'Down' ?>"></span><span><?= h($interfaceTypeLabel($interface)) ?></span></span></td>
                                <td class="mono interface-rate-value is-rx" data-interface-rx><?= h($formatRate($interfaceRate['rx_bps']??null)) ?></td>
                                <td class="mono interface-rate-value is-tx" data-interface-tx><?= h($formatRate($interfaceRate['tx_bps']??null)) ?></td>
                                <td class="interface-record-cell">
                                    <label class="pon-catat-switch" title="Simpan riwayat trafik interface ini">
                                        <input type="checkbox" role="switch" data-traffic-record
                                               data-device-id="<?= (int)$deviceDetail['id'] ?>"
                                               data-if-ref="<?= h($interfaceName) ?>"
                                               data-csrf="<?= h(csrf_token()) ?>"<?= isset($deviceTrafficSelected[$interfaceName]) ? ' checked' : '' ?>>
                                    </label>
                                </td>
                            </tr><?php endforeach; ?>
                        <?php if ($pppoeInterfaces === []): ?><tr>
                                <td colspan="5" class="empty-cell">Belum ada interface PPPoE.</td>
                            </tr><?php endif; ?>
                        <tr data-interface-filter-empty hidden>
                            <td colspan="5" class="empty-cell">Nama PPPoE tidak ditemukan.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="interface-list-pagination" data-interface-pagination><small data-interface-page-info></small>
                <div><button type="button" class="icon-button" data-interface-page-prev aria-label="Halaman sebelumnya"><i class="bi bi-chevron-left"></i></button><span data-interface-page-number></span><button type="button" class="icon-button" data-interface-page-next aria-label="Halaman berikutnya"><i class="bi bi-chevron-right"></i></button></div>
            </div>
        </section>
