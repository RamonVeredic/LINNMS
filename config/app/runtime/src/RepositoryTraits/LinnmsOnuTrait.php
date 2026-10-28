<?php

declare(strict_types=1);
trait LinnmsOnuTrait
{
    public function saveProvisionedOnu(int $userId, int $oltDeviceId, string $ref, string $serial, string $name, string $location, ?int $ponId = null): int
    {
        if (preg_match('~^\d+/\d+/\d+:(?:[1-9]\d?|1[01]\d|12[0-8])$~D', $ref) !== 1
            || preg_match('/^[A-Z0-9]{12,16}$/D', $serial) !== 1) throw new InvalidArgumentException('Identitas ONU tidak valid.');
        return $this->onuWriteTransaction(function () use ($userId, $oltDeviceId, $ref, $serial, $name, $location, $ponId): int {
            $check = $this->pdo->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1 FOR UPDATE');
            $check->execute(['uid' => $userId, 'dev' => $oltDeviceId, 'idx' => $ref]);
            if ($check->fetchColumn()) throw new RuntimeException('ONU ID sudah tercatat pada aplikasi.');
            $insert = $this->pdo->prepare("INSERT INTO onus(user_id,olt_device_id,pon_id,onu_index,onu_serial,onu_name,onu_location,onu_state,auth_mode,auth_confirmed_once,is_present,missing_at) VALUES (:uid,:dev,:pon,:idx,:serial,:name,:location,'Unknown','Pass',1,1,0)");
            $insert->execute(['uid' => $userId, 'dev' => $oltDeviceId, 'pon' => $ponId, 'idx' => $ref, 'serial' => $serial, 'name' => $name, 'location' => $this->nullText(onu_location_value($location))]);
            $rowId = (int)$this->pdo->lastInsertId();
            return $rowId;
        });
    }
    private function buildOnusListWhere(int $userId, array $filters): array
    {
        $where = ['n.user_id=:uid', 'n.is_present=1'];
        $params = ['uid' => $userId];

        $where[] = "n.auth_confirmed_once=1";

        $olt = isset($filters['olt']) && $filters['olt'] !== null ? (int)$filters['olt'] : 0;
        if ($olt > 0) { $where[] = 'n.olt_device_id=:olt'; $params['olt'] = $olt; }

        $source = trim((string)($filters['source'] ?? ''));
        if ($source !== '') { $where[] = 'o.source=:source'; $params['source'] = $source; }

        $deviceName = trim((string)($filters['device'] ?? ''));
        if ($deviceName !== '') { $where[] = 'o.name=:device_name'; $params['device_name'] = $deviceName; }

        $pon = trim((string)($filters['pon'] ?? ''));
        if ($pon !== '') {
            $pipe = strpos($pon, '|');
            $ponDevice = $pipe === false ? 0 : (int)substr($pon, 0, $pipe);
            $ponPath = $pipe === false ? '' : strtolower(trim(substr($pon, $pipe + 1)));
            if ($ponDevice > 0 && $ponPath !== '') {
                $where[] = 'n.olt_device_id=:pon_dev';
                $where[] = "REPLACE(LOWER(SUBSTRING_INDEX(n.onu_index,':',1)),'_','/')=:pon_path";
                $params['pon_dev'] = $ponDevice;
                $params['pon_path'] = $ponPath;
            }
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(n.onu_name LIKE :q1 OR n.onu_location LIKE :q2 OR n.onu_serial LIKE :q3 OR n.onu_index LIKE :q4 OR o.name LIKE :q5)";
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like; $params['q4'] = $like; $params['q5'] = $like;
        }

        $status = strtolower(trim((string)($filters['status'] ?? '')));
        switch ($status) {
            case 'online':
                $where[] = "LOWER(TRIM(n.onu_state)) IN ('online','up','active','working','linkup')";
                break;
            case 'down':
                $where[] = "LOWER(TRIM(n.onu_state)) IN ('offline','down','inactive','linkdown')";
                break;
            case 'powerdown':
                $where[] = "LOWER(TRIM(n.onu_state)) IN ('powerdown','pwrdown','dyinggasp')";
                break;
            case 'loss':
                $where[] = "(LOWER(n.onu_state) LIKE '%loss%' OR LOWER(TRIM(n.onu_state)) IN ('los','lost'))";
                break;
            case 'downall':
                $where[] = "LOWER(TRIM(n.onu_state)) IN ('offline','down','inactive','linkdown','powerdown','pwrdown','dyinggasp')";
                break;
        }

        if (array_key_exists('ids', $filters)) {
            $ids = is_array($filters['ids']) ? $filters['ids'] : [];
            $ids = array_slice(array_values(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)), 0, 1000);
            $where[] = $ids === [] ? '1=0' : 'n.id IN (' . implode(',', $ids) . ')';
        }

        return [implode(' AND ', $where), $params];
    }

    
    public function updateSnmpOnuLabels(int $userId, int $oltDeviceId, array $rows): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $rows === []) return 0;
        $stmt = $this->pdo->prepare(
            'UPDATE onus SET onu_name=:name, onu_location=:location
             WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx'
        );
        $written = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ponNumber = (int)($row['pon_number'] ?? 0);
            $onuId = (int)($row['onu_id'] ?? 0);
            if ($ponNumber < 1 || $onuId < 1) continue;
            $parsed = onu_split_device_label((string)($row['name'] ?? ''));
            $name = $this->usableSyncedOnuName($parsed['name']);
            if ($name === null) continue;
            $this->executeInventoryWrite($stmt, [
                'name' => $name,
                'location' => $this->nullText(onu_location_value($parsed['location'])),
                'uid' => $userId, 'dev' => $oltDeviceId,
                'idx' => '0/1/' . $ponNumber . ':' . $onuId,
            ]);
            if ($stmt->rowCount() > 0) $written++;
        }
        return $written;
    }

    
    public function saveOnuEthernetPorts(int $userId, int $oltDeviceId, array $onusPorts, array $countsByIf = []): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || ($onusPorts === [] && $countsByIf === [])) return 0;
        return $this->onuWriteTransaction(function () use ($userId, $oltDeviceId, $onusPorts, $countsByIf): int {
            $stmt = $this->pdo->prepare('SELECT id,onu_index,port_lan_json FROM onus WHERE user_id=:uid AND olt_device_id=:dev FOR UPDATE');
            $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
            $byIf = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $if = $this->onuInterfaceRef((string)$row['onu_index']);
                $byIf[(string)$if] = [
                    'id' => (int)$row['id'],
                    'ports' => json_decode((string)($row['port_lan_json'] ?? ''), true),
                ];
            }
            $patch = $this->pdo->prepare(
                "UPDATE onus SET port_lan_json=JSON_SET(COALESCE(port_lan_json,'[]'),"
                . ':mode_path,:mode,:vlan_path,:vlan,:dhcp_path,:dhcp,:change_path,JSON_EXTRACT(:change_json,\'$\'))'
                . ' WHERE id=:id AND user_id=:uid AND olt_device_id=:dev'
            );
            $append = $this->pdo->prepare(
                "UPDATE onus SET port_lan_json=JSON_ARRAY_APPEND(COALESCE(port_lan_json,'[]'),'$',JSON_EXTRACT(:port_json,'$'))"
                . ' WHERE id=:id AND user_id=:uid AND olt_device_id=:dev'
            );
            $written = 0;
            foreach ($onusPorts as $onuIf => $ports) {
                $onuRow = $byIf[(string)$onuIf] ?? null;
                $onuId = (int)($onuRow['id'] ?? 0);
                if ($onuId < 1 || !is_array($ports)) continue;
                $oldPorts = [];
                foreach ((array)($onuRow['ports'] ?? []) as $position => $oldPort) {
                    if (!is_array($oldPort)) continue;
                    $oldNumber = (int)($oldPort['port'] ?? $oldPort['port_id'] ?? $oldPort['id'] ?? 0);
                    if ($oldNumber > 0) $oldPorts[$oldNumber] = ['data'=>$oldPort, 'position'=>(int)$position];
                }
                $changed = false;
                foreach ($ports as $port) {
                    if (!is_array($port)) continue;
                    $number = (int)($port['port'] ?? $port['port_id'] ?? $port['id'] ?? 0);
                    if ($number < 1) continue;
                    $oldEntry = $oldPorts[$number] ?? null;
                    $old = is_array($oldEntry) ? (array)$oldEntry['data'] : [];
                    $mode = trim((string)($port['mode'] ?? $port['vlan_mode'] ?? ''));
                    if (strtolower($mode) === 'tag') $mode = 'access';
                    $pvid = trim((string)($port['pvid'] ?? ''));
                    $vlanIds = array_values(array_filter(array_map('trim', explode(',', (string)($port['vlan_list'] ?? ''))), static fn(string $v): bool => $v !== ''));
                    $vlanParts = [];
                    if ($pvid !== '') $vlanParts[] = $pvid . ' access';
                    foreach ($vlanIds as $vlanId) $vlanParts[] = $vlanId . ' trunk';
                    $vlan = array_key_exists('vlan', $port) ? trim((string)$port['vlan']) : implode(',', $vlanParts);
                    $dhcpValue = trim((string)($port['dhcp'] ?? ''));
                    $oldChange = array_replace([
                        'mode' => $mode,
                        'vlan_mode' => $vlan,
                        'dhcp' => $dhcpValue,
                    ], is_array($old['change'] ?? null) ? $old['change'] : [], is_array($port['change'] ?? null) ? $port['change'] : []);
                    $change = [
                        'mode' => (string)($oldChange['mode'] ?? ''),
                        'vlan_mode' => (string)($oldChange['vlan_mode'] ?? ''),
                        'dhcp' => (string)($oldChange['dhcp'] ?? ''),
                    ];
                    if ($oldEntry !== null) {
                        $path = '$[' . (int)$oldEntry['position'] . ']';
                        $this->executeInventoryWrite($patch, [
                            'mode_path'=>$path . '.mode', 'mode'=>$mode,
                            'vlan_path'=>$path . '.vlan', 'vlan'=>$vlan,
                            'dhcp_path'=>$path . '.dhcp', 'dhcp'=>$dhcpValue,
                            'change_path'=>$path . '.change',
                            'change_json'=>json_encode($change, JSON_UNESCAPED_SLASHES),
                            'id'=>$onuId, 'uid'=>$userId, 'dev'=>$oltDeviceId,
                        ]);
                    } else {
                        $newPort = [
                            'port'=>$number,
                            'mode'=>$mode, 'vlan'=>$vlan, 'dhcp'=>$dhcpValue, 'change'=>$change,
                        ];
                        $this->executeInventoryWrite($append, [
                            'port_json'=>json_encode($newPort, JSON_UNESCAPED_SLASHES),
                            'id'=>$onuId, 'uid'=>$userId, 'dev'=>$oltDeviceId,
                        ]);
                        $oldPorts[$number] = ['data'=>$newPort, 'position'=>count((array)($onuRow['ports'] ?? []))];
                        $onuRow['ports'][] = $newPort;
                    }
                    $changed = true;
                }
                if ($changed) $written++;
            }
            foreach ($countsByIf as $ifRef => $count) {
                $onuRowId = (int)($byIf[(string)$ifRef]['id'] ?? 0);
                if ($onuRowId < 1 || !is_numeric($count) || (int)$count < 0) continue;
                $this->mergeOnuJson($onuRowId, $userId, 'detail_json', ['port_count' => (int)$count], [], 'low');
            }
            return $written;
        });
    }

    private function onuInterfaceRef(string $onuIndex): string
    {
        $parts = explode('/', $onuIndex);
        return count($parts) >= 3 ? implode('/', array_slice($parts, 1)) : $onuIndex;
    }

    private function onuWriteTransaction(callable $write): mixed
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) $this->pdo->beginTransaction();
        try {
            $result = $write();
            if ($ownTransaction) $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    
    public function getOnuEthernetPorts(int $userId, int $onuRowId): array
    {
        $stmt = $this->pdo->prepare('SELECT port_lan_json FROM onus WHERE id=:id AND user_id=:uid LIMIT 1');
        $this->executeInventoryWrite($stmt, ['id' => $onuRowId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $json = (string)($row['port_lan_json'] ?? '');
        $ports = json_decode($json, true);
        if (!is_array($ports)) return [];
        return $ports;
    }

    public function updateOnuEthernetPortConfigurations(int $userId, int $oltDeviceId, array $configs): int
    {
        if ($configs === []) return 0;
        return $this->onuWriteTransaction(function () use ($userId, $oltDeviceId, $configs): int {
            $stmt = $this->pdo->prepare('SELECT id,onu_index,port_lan_json FROM onus WHERE user_id=:uid AND olt_device_id=:dev FOR UPDATE');
            $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
            $update = $this->pdo->prepare('UPDATE onus SET port_lan_json=:json WHERE id=:id AND user_id=:uid');
            $written = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $byPort = $configs[(string)$row['onu_index']] ?? null;
                if (!is_array($byPort) || $byPort === []) continue;
                $ports = json_decode((string)$row['port_lan_json'], true);
                if (!is_array($ports)) $ports = [];
                $indexed = [];
                foreach ($ports as $port) {
                    if (!is_array($port)) continue;
                    $number = (int)($port['port'] ?? 0);
                    if ($number > 0) $indexed[$number] = $port;
                }
                $changed = false;
                foreach ($byPort as $number => $config) {
                    $number = (int)$number;
                    if ($number < 1 || !is_array($config)) continue;
                    $port = $indexed[$number] ?? ['port' => $number, 'link_status' => '', 'speed' => ''];
                    $action = is_array($port['action'] ?? null) ? $port['action'] : [];
                    foreach (['enable' => 'interface_status', 'autoneg' => 'autoneg'] as $source => $target) {
                        $value = (string)($config[$source] ?? '');
                        if (!in_array($value, ['enable', 'disable'], true) || ($action[$target] ?? null) === $value) continue;
                        $action[$target] = $value;
                        $changed = true;
                    }
                    $controlBytes = strtoupper((string)($config['control_bytes'] ?? ''));
                    if (preg_match('/^[0-9A-F]{14}$/D', $controlBytes) === 1
                        && (string)($port['control_bytes'] ?? '') !== $controlBytes) {
                        $port['control_bytes'] = $controlBytes;
                        $changed = true;
                    }
                    $port['action'] = $action;
                    $indexed[$number] = $port;
                }
                if (!$changed) continue;
                ksort($indexed, SORT_NUMERIC);
                $this->executeInventoryWrite($update, ['json' => json_encode(array_values($indexed), JSON_UNESCAPED_SLASHES), 'id' => (int)$row['id'], 'uid' => $userId]);
                $written++;
            }
            return $written;
        });
    }

    public function updateOnuEthernetPortStatuses(int $userId, int $oltDeviceId, array $statuses, ?array $scopeRefs = null, bool $dynamicPorts = false, array $configs = []): int
    {
        return $this->onuWriteTransaction(function () use ($userId, $oltDeviceId, $statuses, $scopeRefs, $dynamicPorts, $configs): int {
            $scope = $scopeRefs === null ? null : array_fill_keys(array_map('strval', $scopeRefs), true);
            $sql = 'SELECT n.id,n.onu_index,n.port_lan_json AS ports_json,n.detail_json FROM onus n WHERE n.user_id=:uid AND n.olt_device_id=:dev';
            $stmt = $this->pdo->prepare($sql . ' FOR UPDATE');
            $this->executeInventoryWrite($stmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
            $update = $this->pdo->prepare('UPDATE onus SET port_lan_json=:json WHERE id=:id AND user_id=:uid');
            $written = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $ref = (string)$row['onu_index'];
                if ($scope !== null && !isset($scope[$ref])) continue;
                $ports = json_decode((string)$row['ports_json'], true);
                if (!is_array($ports)) $ports = [];
                $changed = false;
                if ($dynamicPorts) {
                    $detail = json_decode((string)($row['detail_json'] ?? ''), true);
                    $count = is_array($detail) && is_numeric($detail['port_count'] ?? null) ? (int)$detail['port_count'] : 0;
                    if ($count < 1) continue;
                    $existing = [];
                    foreach ($ports as $port) {
                        if (!is_array($port)) continue;
                        $number = (int)($port['port'] ?? 0);
                        if ($number > 0) $existing[$number] = $port;
                    }
                    $ordered = $existing;
                    for ($number = 1; $number < $count; $number++) {
                        $ordered[$number] = $existing[$number] ?? ['port' => $number, 'link_status' => '', 'speed' => ''];
                    }
                    ksort($ordered, SORT_NUMERIC);
                    $ordered = array_values($ordered);
                    $changed = $ordered !== $ports;
                    $ports = $ordered;
                }
                if ($ports === [] && isset($statuses[$ref]) && is_array($statuses[$ref])) {
                    foreach (array_keys($statuses[$ref]) as $number) {
                        if ((int)$number > 0) $ports[] = ['port'=>(int)$number, 'link_status'=>'', 'speed'=>''];
                    }
                    $changed = $ports !== [];
                }
                if ($ports === []) continue;
                foreach ($ports as &$port) {
                    if (!is_array($port)) continue;
                    $number = (int)($port['port'] ?? 0);
                    $config = $configs[$ref][$number] ?? null;
                    if (is_array($config)) {
                        $action = is_array($port['action'] ?? null) ? $port['action'] : [];
                        foreach (['enable' => 'interface_status', 'autoneg' => 'autoneg'] as $source => $target) {
                            $value = (string)($config[$source] ?? '');
                            if (!in_array($value, ['enable', 'disable'], true) || ($action[$target] ?? null) === $value) continue;
                            $action[$target] = $value;
                            $changed = true;
                        }
                        $port['action'] = $action;
                        $controlBytes = strtoupper((string)($config['control_bytes'] ?? ''));
                        if (preg_match('/^[0-9A-F]{14}$/D', $controlBytes) === 1
                            && (string)($port['control_bytes'] ?? '') !== $controlBytes) {
                            $port['control_bytes'] = $controlBytes;
                            $changed = true;
                        }
                    }
                    $status = $statuses[$ref][$number] ?? null;
                    if (!is_array($status)) {
                        if ((string)($port['link_status'] ?? '') !== '') {
                            $port['link_status'] = '';
                            $changed = true;
                        }
                        if ($dynamicPorts && (string)($port['speed'] ?? '') !== '') {
                            $port['speed'] = '';
                            $changed = true;
                        }
                        continue;
                    }
                    foreach (['link_status' => 'link', 'speed' => 'speed', 'enable' => 'enable'] as $field => $sourceField) {
                        if (!array_key_exists($sourceField, $status)) continue;
                        $value = $field === 'speed'
                            ? (string)$status[$sourceField]
                            : strtolower((string)$status[$sourceField]);
                        if ($field === 'enable') {
                            $action = is_array($port['action'] ?? null) ? $port['action'] : [];
                            if ((string)($action['interface_status'] ?? '') === $value) continue;
                            $port['action'] = array_replace($action, ['interface_status' => $value]);
                            unset($port['enable']);
                        } else {
                            if ((string)($port[$field] ?? '') === $value) continue;
                            $port[$field] = $value;
                        }
                        $changed = true;
                    }
                }
                unset($port);
                if (!$changed) continue;
                $this->executeInventoryWrite($update, ['json' => json_encode($ports, JSON_UNESCAPED_SLASHES), 'id' => (int)$row['id'], 'uid' => $userId]);
                $written++;
            }
            return $written;
        });
    }

    public function updateOnuLanControlSnapshot(int $userId, int $onuRowId, int $portId, string $field, string $value, string $controlBytes): bool
    {
        if ($userId < 1 || $onuRowId < 1 || $portId < 1
            || !in_array($field, ['enable', 'autoneg'], true)
            || !in_array($value, ['enable', 'disable'], true)) return false;
        $controlBytes = strtoupper($controlBytes);
        $hasControlBytes = preg_match('/^[0-9A-F]{14}$/D', $controlBytes) === 1;
        return $this->onuWriteTransaction(function () use ($userId, $onuRowId, $portId, $field, $value, $controlBytes): bool {
            $stmt = $this->pdo->prepare('SELECT port_lan_json FROM onus WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE');
            $this->executeInventoryWrite($stmt, ['id' => $onuRowId, 'uid' => $userId]);
            $ports = json_decode((string)$stmt->fetchColumn(), true);
            if (!is_array($ports)) $ports = [];
            $updated = false;
            foreach ($ports as &$port) {
                if (!is_array($port) || (int)($port['port'] ?? 0) !== $portId) continue;
                $action = is_array($port['action'] ?? null) ? $port['action'] : [];
                $action[$field === 'enable' ? 'interface_status' : 'autoneg'] = $value;
                $port['action'] = $action;
                if ($hasControlBytes) $port['control_bytes'] = $controlBytes;
                unset($port['enable']);
                $updated = true;
                break;
            }
            unset($port);
            if (!$updated) {
                $action = [$field === 'enable' ? 'interface_status' : 'autoneg' => $value];
                $newPort = [
                    'port' => $portId,
                    'link_status' => '',
                    'speed' => '',
                    'action' => $action,
                ];
                if ($hasControlBytes) $newPort['control_bytes'] = $controlBytes;
                $ports[] = $newPort;
            }
            $save = $this->pdo->prepare('UPDATE onus SET port_lan_json=:json WHERE id=:id AND user_id=:uid');
            $this->executeInventoryWrite($save, ['json' => json_encode(array_values($ports), JSON_UNESCAPED_SLASHES), 'id' => $onuRowId, 'uid' => $userId]);
            return true;
        });
    }

    public function updateOnuEthernetPortField(int $userId, int $onuRowId, int $portId, string $field, string $value): bool
    {
        if ($userId < 1 || $onuRowId < 1) return false;
        return $this->onuWriteTransaction(function () use ($userId, $onuRowId, $portId, $field, $value): bool {
            $stmt = $this->pdo->prepare('SELECT port_lan_json FROM onus WHERE id=:id AND user_id=:uid LIMIT 1 FOR UPDATE');
            $this->executeInventoryWrite($stmt, ['id' => $onuRowId, 'uid' => $userId]);
            $ports = json_decode((string)$stmt->fetchColumn(), true);
            if (!is_array($ports)) $ports = [];
            $updated = false;
            foreach ($ports as &$port) {
                if (!is_array($port)) continue;
                if ((int)($port['port'] ?? $port['port_id'] ?? $port['id'] ?? 0) !== $portId) continue;
                if ($field === 'enable') {
                    $action = is_array($port['action'] ?? null) ? $port['action'] : [];
                    $port['action'] = array_replace($action, ['interface_status' => strtolower($value)]);
                    unset($port['enable']);
                } elseif ($field === 'autoneg') {
                    $action = is_array($port['action'] ?? null) ? $port['action'] : [];
                    $port['action'] = array_replace($action, ['autoneg' => strtolower($value)]);
                } elseif ($field === 'change') {
                    $change = is_array($port['change'] ?? null) ? $port['change'] : [];
                    $patch = json_decode($value, true);
                    if (!is_array($patch)) return false;
                    $port['change'] = array_replace($change, $patch);
                } else {
                    $port[$field] = $value;
                }
                $updated = true;
                break;
            }
            unset($port);
            if (!$updated && in_array($field, ['enable', 'autoneg'], true)) {
                $ports[] = [
                    'port' => $portId,
                    'link_status' => '',
                    'speed' => '',
                    'action' => [$field === 'enable' ? 'interface_status' : 'autoneg' => strtolower($value)],
                ];
                $updated = true;
            }
            if (!$updated) return false;
            $upd = $this->pdo->prepare('UPDATE onus SET port_lan_json=:json WHERE id=:id AND user_id=:uid');
            $this->executeInventoryWrite($upd, ['json' => json_encode(array_values($ports), JSON_UNESCAPED_SLASHES), 'id' => $onuRowId, 'uid' => $userId]);
            return true;
        });
    }

    public function getUnregisteredOnusByDevice(int $userId, int $oltDeviceId, int $excludeOnuId = 0): array
    {
        if ($userId < 1 || $oltDeviceId < 1) return [];
        $stmt = $this->pdo->prepare(
            "SELECT id,olt_device_id,onu_index,onu_serial,onu_name,auth_mode,auth_confirmed_once
             FROM onus
             WHERE user_id=:uid AND olt_device_id=:device
               AND COALESCE(NULLIF(auth_mode,''),'Undef') IN ('Undef','uncfg') AND is_present=1
               AND onu_serial IS NOT NULL AND onu_serial<>''
               AND id<>:exclude
             ORDER BY onu_index"
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'device' => $oltDeviceId, 'exclude' => max(0, $excludeOnuId)]);
        return $stmt->fetchAll() ?: [];
    }

    public function getOnusListPage(int $userId, array $filters, int $limit = 50, int $offset = 0): array
    {
        if ($userId < 1) return [];
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        [$where, $params] = $this->buildOnusListWhere($userId, $filters);
        $stmt = $this->pdo->prepare(
            "SELECT n.*,o.source,o.olt_id,o.type,SUBSTRING_INDEX(n.onu_index,':',1) physical,o.name olt_name"
            . " FROM onus n INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt'"
            . " WHERE " . $where
            . " ORDER BY (n.auth_confirmed_once=0) DESC, n.olt_device_id ASC, o.source ASC, n.onu_index ASC, n.id ASC"
            . " LIMIT " . $limit . " OFFSET " . $offset
        );
        $this->executeInventoryWrite($stmt, $params);
        return $stmt->fetchAll() ?: [];
    }

    public function getOnusListDeviceCounts(int $userId, array $filters): array
    {
        if ($userId < 1) return [];
        $filters = $filters;
        unset($filters['device']);
        [$where, $params] = $this->buildOnusListWhere($userId, $filters);
        $stmt = $this->pdo->prepare(
            "SELECT o.name AS label, COUNT(*) AS total FROM onus n INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt'"
            . " WHERE " . $where . " GROUP BY o.name ORDER BY o.name"
        );
        $this->executeInventoryWrite($stmt, $params);
        $devices = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') continue;
            $devices[] = ['label' => $label, 'count' => (int)($row['total'] ?? 0)];
        }
        return $devices;
    }

    public function countOnusList(int $userId, array $filters): int
    {
        if ($userId < 1) return 0;
        [$where, $params] = $this->buildOnusListWhere($userId, $filters);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM onus n INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt' WHERE " . $where
        );
        $this->executeInventoryWrite($stmt, $params);
        return (int)$stmt->fetchColumn();
    }

    public static function clampOnuRxDetection(mixed $value): float
    {
        $detection = is_numeric($value) ? (float)$value : self::ONU_RX_DETECTION_DEFAULT_DBM;

        return max(self::ONU_RX_DETECTION_MIN_DBM, min(self::ONU_RX_DETECTION_MAX_DBM, $detection));
    }

    public static function clampOnuRxProblemLimit(mixed $value): float
    {
        $limit = is_numeric($value) ? abs((float)$value) : self::ONU_RX_PROBLEM_DEFAULT_DBM;

        return max(self::ONU_RX_PROBLEM_MIN_DBM, min(self::ONU_RX_PROBLEM_MAX_DBM, $limit));
    }

    public function getOnuProblemSettings(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM user_settings WHERE user_id=:id AND setting_key='onu_rx_problem_threshold_dbm' LIMIT 1");
        $this->executeInventoryWrite($stmt, ['id' => $userId]);
        $value = $stmt->fetchColumn();

        return [
            'rx_power_threshold_dbm' => self::clampOnuRxProblemLimit($value),
            'offline_after_hours' => self::ONU_PROBLEM_AFTER_HOURS,
        ];
    }

    public function saveOnuRxProblemThreshold(float $thresholdDbm, int $userId): void
    {
        $value = number_format(self::clampOnuRxProblemLimit($thresholdDbm), 2, '.', '');
        $this->saveUserSetting($userId, 'onu_rx_problem_threshold_dbm', $value);
    }

    public function getOnuMissingScanLimit(): int
    {
        $value = $this->appSettingOrDefault('onu_missing_scan_limit', (string)self::DEFAULT_ONU_MISSING_SCAN_LIMIT);
        $limit = is_numeric($value) ? (int)$value : self::DEFAULT_ONU_MISSING_SCAN_LIMIT;
        return max(self::MIN_ONU_MISSING_SCAN_LIMIT, min(self::MAX_ONU_MISSING_SCAN_LIMIT, $limit));
    }

    public function saveOnuMissingScanLimit(int $limit): void
    {
        if ($limit < self::MIN_ONU_MISSING_SCAN_LIMIT || $limit > self::MAX_ONU_MISSING_SCAN_LIMIT) {
            throw new InvalidArgumentException('Batas pembacaan ONU hilang harus antara 1 sampai 10 kali.');
        }
        $this->saveAppSetting('onu_missing_scan_limit', (string)$limit);
    }

    public function replaceSnmpOnus(
        int $userId,
        int $oltId,
        array $rows,
        bool $recordChanges = true,
        bool $completeInventory = true,
        bool $unconfiguredComplete = false,
        string $snapshotSource = 'sync',
        array $seenIndexes = []
    ): array
    {
        return $this->onuWriteTransaction(function () use ($userId, $oltId, $rows, $recordChanges, $completeInventory, $unconfiguredComplete, $snapshotSource, $seenIndexes): array {
            $events = [];
            $oltDeviceId = $this->oltDeviceId($userId, 'snmp', $oltId);
            $settings = $this->getUserSettings($userId);
            $signalThreshold = effective_signal_threshold_dbm($settings);
            $rxProblemThreshold = $this->getOnuProblemSettings($userId)['rx_power_threshold_dbm'];

            $oltNameStmt = $this->pdo->prepare("SELECT d.id,d.name,st.driver_status,s.first_scan_started_at FROM devices d LEFT JOIN device_scan_state s ON s.device_id=d.id LEFT JOIN device_status st ON st.device_id=d.id WHERE d.user_id=:uid AND d.device_type='olt' AND d.source='snmp' AND d.olt_id=:oid LIMIT 1 FOR UPDATE");
            $this->executeInventoryWrite($oltNameStmt, ['uid'=>$userId,'oid'=>$oltId]);
            $oltBefore = $oltNameStmt->fetch();
            $oltName = display_text(is_array($oltBefore) ? ($oltBefore['name'] ?? null) : null);

            $existing = [];
            $missingLimit = max(2, $this->getOnuMissingScanLimit());
            foreach ($this->getOnus($userId, $oltId, false, 'snmp') as $onu) {
                $existing[(string)$onu['onu_index']] = $onu;
            }
            $isInitialBaseline = empty($oltBefore['first_scan_started_at']) && $existing === [];
            if ($isInitialBaseline) $recordChanges = false;
            $fastSync = $snapshotSource === 'fast_sync';
            if (!in_array($snapshotSource, ['fast_sync', 'stream_finish'], true)) $recordChanges = false;
            $typeColumn = $fastSync ? '' : 'onu_type,';
            $typeValue = $fastSync ? '' : ':onu_type,';
            $typeUpdate = $fastSync ? '' : 'onu_type=COALESCE(VALUES(onu_type),onu_type),';
            $sql = "INSERT INTO onus(user_id,olt_device_id,pon_id,onu_index,{$typeColumn}onu_serial,onu_name,onu_state,auth_mode,auth_confirmed_once,rx_power,onu_location,is_present,missing_at) VALUES (:uid,:olt_device_id,:pon_id,:idx,{$typeValue}:serial,:name,:state,:auth,:auth_confirmed,:rx,:location,1,0) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),pon_id=COALESCE(VALUES(pon_id),pon_id),{$typeUpdate}onu_serial=COALESCE(VALUES(onu_serial),onu_serial),onu_name=VALUES(onu_name),onu_state=VALUES(onu_state),auth_mode=COALESCE(VALUES(auth_mode),auth_mode),auth_confirmed_once=IF(auth_mode IS NULL,auth_confirmed_once,IF(auth_mode IN ('Pass','Deny'),1,0)),rx_power=COALESCE(VALUES(rx_power),rx_power),onu_location=VALUES(onu_location),is_present=1,missing_at=0";
            $upsert = $this->pdo->prepare($sql);
            $updateOpticalPower = $this->pdo->prepare('UPDATE onus SET rx_power=:rx WHERE id=:id AND user_id=:uid AND olt_device_id=:dev');
            $ponIdByNumber = [];
            $ponMapStmt = $this->pdo->prepare('SELECT id,pon_number FROM pons WHERE user_id=:uid AND olt_device_id=:dev');
            $this->executeInventoryWrite($ponMapStmt, ['uid' => $userId, 'dev' => $oltDeviceId]);
            foreach ($ponMapStmt->fetchAll() ?: [] as $p) { $ponIdByNumber[(int)$p['pon_number']] = (int)$p['id']; }
            $markMissingOnu = $this->pdo->prepare(
                "UPDATE onus SET is_present=0,onu_state='Lost',missing_at=COALESCE(missing_at,0)+1 WHERE id=:id AND user_id=:uid AND olt_device_id=:olt_device_id"
            );
            $deleteMissingOnu = $this->pdo->prepare(
                "DELETE FROM onus WHERE id=:id AND user_id=:uid AND olt_device_id=:olt_device_id"
            );
            $seen = [];
            foreach ($seenIndexes as $seenIndex) {
                $seenIndex = trim((string)$seenIndex);
                if ($seenIndex !== '') $seen[$seenIndex] = true;
            }
            $missingIndexes = [];
            $written = 0;
            
            
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $pon = trim((string)($row['pon'] ?? ''));
                $onuId = trim((string)($row['onu_id'] ?? ''));
                if ($pon === '' || $onuId === '') continue;
                $index = $pon . ':' . $onuId;
                $identity = $this->onuIdentity('snmp', $oltId, $index);
                $old = $existing[$index] ?? null;
                if ($snapshotSource === 'optical_action' && !is_array($old)) continue;
                if (!empty($row['missing'])) {
                    $missingIndexes[$index] = true;
                    if (is_array($old)) {
                        $missingWrite = (int)($old['missing_at'] ?? 0) + 1 >= $missingLimit
                            ? $deleteMissingOnu : $markMissingOnu;
                        $this->executeInventoryWrite($missingWrite, [
                            'id'=>(int)$old['id'],
                            'uid'=>$userId,
                            'olt_device_id'=>$oltDeviceId,
                        ]);
                        $confirmedMissing = $recordChanges
                            ? $this->confirmScannedStatusTracker(
                                $userId, 'ONU', $identity, 'is_present',
                                $old['is_present'] ?? 1, 0, 2
                            )
                            : null;
                        if ($confirmedMissing !== null && $confirmedMissing['new'] === '0') {
                            $events[] = $this->insertChangeEvent(
                                $userId,'ONU',$identity,
                                'is_present',$confirmedMissing['old'],$confirmedMissing['new'],
                                $oltName . ' ' . $index . ' ONU ' . display_onu_name($old['onu_name'] ?? null)
                                    . ' tidak lagi terdeteksi di OLT.',
                                [
                                    'source'=>'snmp','olt_id'=>$oltId,
                                    'onu_index'=>$index,
                                    'onu_name'=>$this->nullText($old['onu_name'] ?? null),
                                    'onu_serial'=>$this->nullText($old['onu_serial'] ?? null),
                                    'onu_location'=>$this->nullText($old['onu_location'] ?? null),
                                ]
                            );
                        }
                    }
                    continue;
                }
                $rxRejected = false;
                $row = onu_strip_rejected_rx($row, $rxRejected);
                if (is_array($old) && onu_rx_power_rejected($old['rx_power'] ?? null)) {
                    $this->clearRejectedOnuRx((int)$old['id'], $userId);
                }
                $incomingLabel = onu_split_device_label($row['name'] ?? $row['label'] ?? null);
                $incomingName = $this->nullText($incomingLabel['name']);
                if (in_array($incomingName, ['—', '-', '_'], true)) $incomingName = null;
                $locationFromDevice = $this->nullText(onu_location_value($row['location'] ?? null));
                if ($locationFromDevice === null) $locationFromDevice = $this->nullText($incomingLabel['location']);
                if ($locationFromDevice === null && is_array($old)) $locationFromDevice = $this->nullText(onu_location_value($old['onu_location'] ?? null));
                $seen[$index] = true;
                $name = $incomingName ?? $this->nullText($old['onu_name'] ?? null);
                $state = $this->displayOnuState(array_key_exists('status', $row) ? $row['status'] : ($old['onu_state'] ?? null));
                
                
                
                
                $persistedState = $state;
                $authMode = $this->nullText(array_key_exists('auth_mode', $row) ? $row['auth_mode'] : ($old['auth_mode'] ?? null));
                $authConfirmed = in_array($authMode, ['Pass', 'Deny'], true) ? 1 : 0;
                $emitOnuSignalEvents = $authMode !== null && strcasecmp($authMode, 'Undef') !== 0 && strcasecmp($authMode, 'uncfg') !== 0;
                $rxPower = $rxRejected ? null : onu_rx_power_number(array_key_exists('rx_power', $row) ? $row['rx_power'] : ($old['rx_power'] ?? null));
                $context = [
                    'source'=>'snmp','olt_id'=>$oltId,
                    'onu_index'=>$index,'onu_name'=>$name,
                    'onu_serial'=>($this->snmpOnuSerialValue($row) !== '' ? $this->snmpOnuSerialValue($row) : null) ?? $this->nullText(is_array($old) ? ($old['onu_serial'] ?? null) : null),
                    'onu_location'=>$locationFromDevice !== null ? $locationFromDevice : $this->nullText(onu_location_value(is_array($old) ? ($old['onu_location'] ?? null) : null)),
                ];

                $initialStateKey = state_key($state);
                if ($recordChanges && $authConfirmed === 0) {
                    $confirmedFound = $this->confirmScannedStatusTracker(
                        $userId, 'ONU', $identity, 'is_present',
                        '0', $initialStateKey === 'online' ? '1' : '0', 2
                    );
                    if ($confirmedFound !== null && $confirmedFound['new'] === '1') {
                        $events[] = $this->insertChangeEvent(
                            $userId,'ONU',$identity,
                            'is_present',$confirmedFound['old'],$confirmedFound['new'],
                            $oltName . ' ' . $index
                                . (is_array($old) && state_key($old['onu_state'] ?? null) !== 'online'
                                    ? ' ONU kembali online dan ditemukan: '
                                    : ' ONU baru ditemukan: ')
                                . display_onu_name($name) . '.',
                            $context
                        );
                    }
                }
                if ($recordChanges && !is_array($old)) {
                    $this->confirmScannedStatus($userId, 'ONU', $identity, 'onu_state', $state, $state);
                }

                if ($recordChanges && is_array($old)) {
                    $oldState = $this->displayOnuState($old['onu_state'] ?? null);
                    $persistedState = $oldState;
                    $confirmedState = $this->confirmScannedStatus(
                        $userId,'ONU',$identity,'onu_state',
                        $oldState,$state
                    );
                    if ($confirmedState !== null) {
                        $persistedState = $this->displayOnuState($confirmedState['new']);
                        if ($emitOnuSignalEvents) {
                            $events[] = $this->insertChangeEvent(
                                $userId,'ONU',$identity,
                                'onu_state',$confirmedState['old'],$confirmedState['new'],
                                $oltName . ' ' . $index . ' ONU ' . display_onu_name($name) . ' status '
                                    . state_label($confirmedState['old']) . ' -> ' . state_label($confirmedState['new']) . '.',
                                $context
                            );
                        }
                    }
                    $confirmedPower = $this->confirmRxPowerChange(
                        $userId,
                        $identity,
                        onu_rx_power_number($old['rx_power'] ?? null),
                        $rxPower,
                        $signalThreshold
                    );
                    if ($emitOnuSignalEvents && $confirmedPower !== null && $rxPower !== null && $rxPower > -$rxProblemThreshold) {
                        $events[] = $this->insertChangeEvent(
                            $userId,'ONU',$identity,
                            'rx_power',$confirmedPower['old'],$confirmedPower['new'],
                            $oltName . ' ' . $index . ' ONU ' . display_onu_name($name) . ' '
                                . event_field_label('rx_power') . ': ' . format_power($confirmedPower['old']) . ' -> ' . format_power($confirmedPower['new']) . '.',
                            $context
                        );
                    }
                }
                if ($recordChanges) {
                    $confirmedProblem = $this->confirmRxProblemState(
                        $userId,
                        $identity,
                        $rxPower,
                        $rxProblemThreshold
                    );
                    if ($emitOnuSignalEvents && $confirmedProblem !== null && $confirmedProblem['new'] === 'problem' && $rxPower !== null) {
                        $events[] = $this->insertChangeEvent(
                            $userId, 'ONU', $identity,
                            'problem_rx_power', -$rxProblemThreshold, $rxPower,
                            $oltName . ' ' . $index . ' ONU ' . display_onu_name($name)
                                . ' bermasalah karena RX Power ' . format_power($rxPower) ,
                            $context
                        );
                    }
                    if ($emitOnuSignalEvents && $confirmedProblem !== null && $confirmedProblem['new'] === 'normal' && $rxPower !== null) {
                        $events[] = $this->insertChangeEvent(
                            $userId, 'ONU', $identity,
                            'problem_rx_recovered', -$rxProblemThreshold, $rxPower,
                            $oltName . ' ' . $index . ' ONU ' . display_onu_name($name)
                                . ' RX Power kembali normal (' . format_power($rxPower) . ').',
                            $context
                        );
                    }
                }
                $ponNumberForId = (int)($row['pon_number'] ?? 0);
                if ($ponNumberForId < 1 && preg_match('/(\d+)\s*$/', (string)($row['pon'] ?? ''), $pm) === 1) $ponNumberForId = (int)$pm[1];
                $values = ['uid'=>$userId,'olt_device_id'=>$oltDeviceId,'idx'=>$index,
                    'pon_id'=>(int)(($ponIdByNumber[$ponNumberForId] ?? 0)) ?: null,
                    'serial'=>$snapshotSource === 'fast_sync' && ($row['auth_mode'] ?? '') !== 'uncfg'
                        ? null : $this->nullText($row['serial'] ?? $row['mac'] ?? $old['onu_serial'] ?? null),
                    'name'=>$name ?? '',
                    'location'=>$locationFromDevice,
                    'state'=>$persistedState,
                    'auth'=>$authMode,
                    'auth_confirmed'=>$authConfirmed,
                    'rx'=>$rxPower];
                if (!$fastSync) $values['onu_type'] = $this->nullText($row['onu_type'] ?? $old['onu_type'] ?? null);
                if ($snapshotSource === 'optical_action') {
                    $onuRowId = (int)$old['id'];
                    $patch = is_array($row['optical_patch'] ?? null) ? $row['optical_patch'] : [];
                    $newRx = onu_rx_power_number($patch['rx_power'] ?? null);
                    if ($newRx !== null) {
                        $this->executeInventoryWrite($updateOpticalPower, ['rx' => $newRx, 'id' => $onuRowId, 'uid' => $userId, 'dev' => $oltDeviceId]);
                    }
                    $opticalValues = [];
                    foreach (['tx_power', 'olt_rx_power', 'temperature'] as $field) {
                        $value = $this->nullableFloat($patch[$field] ?? null);
                        if ($value !== null) $opticalValues[$field] = $value;
                    }
                    if ($opticalValues !== []) $this->mergeOnuJson($onuRowId, $userId, 'detail_json', $opticalValues);
                    if ($patch !== []) $this->mergeOnuJson($onuRowId, $userId, 'detail_json', ['device_response_optical' => $patch]);
                } else {
                    $this->executeInventoryWrite($upsert, $values);
                    $onuRowId = (int)$this->pdo->lastInsertId();
                    if ($onuRowId > 0) {
                        $detail = [];
                        foreach ($fastSync ? ['temperature'] : ['temperature', 'tx_power', 'distance', 'olt_rx_power'] as $field) {
                            if (array_key_exists($field, $row)) $detail[$field] = $this->nullableFloat($row[$field]);
                        }
                        foreach (['rx_power_raw', 'tx_power_raw', 'temperature_raw', 'olt_rx_power_raw'] as $field) {
                            if (array_key_exists($field, $row)) $detail[$field] = (string)$row[$field];
                        }
                        $detail[$fastSync ? 'device_response_fast' : 'device_response'] = $row;
                        foreach ($fastSync ? [] : ['register_time', 'deregister_time'] as $field) {
                            if (array_key_exists($field, $row)) $detail[$field] = $this->nullText($row[$field]);
                        }
                        foreach ($fastSync ? [] : ['description', 'vendor'] as $field) {
                            if (array_key_exists($field, $row)) $detail[$field] = $this->nullText($row[$field]);
                        }
                        foreach ($fastSync ? [] : ['deregister_count', 'running_time'] as $field) {
                            if (array_key_exists($field, $row)) $detail[$field] = $row[$field] === null ? null : (int)$row[$field];
                        }
                        if (!$fastSync && ($row['port_count_source'] ?? '') === 'hioso_snmp'
                            && isset($row['port_count']) && is_numeric($row['port_count']) && (int)$row['port_count'] > 0) {
                            $detail['port_count'] = (int)$row['port_count'];
                        }
                        $this->mergeOnuJson($onuRowId, $userId, 'detail_json', $detail, [], $fastSync ? 'fast' : null);
                    }
                }
                $this->paceDatabaseWrites(++$written);
            }

            if ($completeInventory || $unconfiguredComplete) {
                foreach ($existing as $existingIndex => $existingOnu) {
                    if (!$completeInventory && !empty($existingOnu['auth_confirmed_once'])) continue;
                    if (isset($seen[$existingIndex]) || isset($missingIndexes[$existingIndex])) continue;
                    $missingWrite = (int)($existingOnu['missing_at'] ?? 0) + 1 >= $missingLimit
                        ? $deleteMissingOnu : $markMissingOnu;
                    $this->executeInventoryWrite($missingWrite, [
                        'id' => (int)$existingOnu['id'],
                        'uid' => $userId,
                        'olt_device_id' => $oltDeviceId,
                    ]);
                }
            }

            $confirmedOltRecovery = $recordChanges && ($snapshotSource === 'stream_finish' || $completeInventory)
                ? $this->confirmScannedStatus(
                    $userId,'OLT','snmp|' . $oltId,'driver_status',
                    $oltBefore['driver_status'] ?? 'OK','OK'
                )
                : null;
            if ($confirmedOltRecovery !== null) {
                $events[] = $this->insertChangeEvent(
                    $userId,'OLT','snmp|' . $oltId,'driver_status',
                    $confirmedOltRecovery['old'],$confirmedOltRecovery['new'],
                    'OLT ' . $oltName . ' kembali online.',
                    ['source'=>'snmp','olt_id'=>$oltId]
                );
            }
            $knownIndexes = array_fill_keys(array_keys(array_filter($existing, static fn(array $onu): bool => (int)($onu['is_present'] ?? 1) === 1)), true);
            foreach (array_keys($seen) as $seenIndex) $knownIndexes[$seenIndex] = true;
            foreach (array_keys($missingIndexes) as $missingIndex) unset($knownIndexes[$missingIndex]);
            
            $oltDeviceId = (int)($oltBefore['id'] ?? 0);
            if ($oltDeviceId > 0) {
                $this->markDeviceOnline(
                    $oltDeviceId,
                    $userId,
                    'OK',
                    $completeInventory ? count($seen) : count($knownIndexes)
                );
                $this->recordConnectionProbe($oltDeviceId, $userId, 'snmp', true);
                $this->touchDeviceFirstScanStarted($oltDeviceId, $userId);
            }
            return $events;
        });
    }

    public function updateSnmpOnuMiddleDetails(int $userId, int $oltId, array $rows): int
    {
        if ($rows === []) return 0;
        return $this->onuWriteTransaction(function () use ($userId, $oltId, $rows): int {
            $oltDeviceId = $this->oltDeviceId($userId, 'snmp', $oltId);
            $find = $this->pdo->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1 FOR UPDATE');
            $serialUpdate = $this->pdo->prepare('UPDATE onus SET onu_serial=:serial WHERE id=:id AND user_id=:uid AND olt_device_id=:dev');
            $written = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $rxRejected = false;
                $row = onu_strip_rejected_rx($row, $rxRejected);
                $pon = trim((string)($row['pon'] ?? ''));
                $onuId = (int)($row['onu_id'] ?? 0);
                if ($pon === '' || $onuId < 1) continue;
                $index = $pon . ':' . $onuId;
                $this->executeInventoryWrite($find, ['uid'=>$userId, 'dev'=>$oltDeviceId, 'idx'=>$index]);
                $onuRowId = (int)$find->fetchColumn();
                if ($onuRowId < 1) throw new RuntimeException('ONU Middle belum tersedia dari Fast Sync: ' . $index);
                $serial = $this->snmpOnuSerialValue($row);
                if ($serial !== '') {
                    $this->executeInventoryWrite($serialUpdate, ['serial'=>$serial, 'id'=>$onuRowId, 'uid'=>$userId, 'dev'=>$oltDeviceId]);
                }
                $detail = [];
                foreach (['tx_power', 'distance', 'olt_rx_power'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $value = $this->nullableFloat($row[$field]);
                        if ($value !== null) $detail[$field] = $value;
                    }
                }
                foreach (['rx_power_raw', 'tx_power_raw', 'temperature_raw', 'olt_rx_power_raw'] as $field) {
                    if (array_key_exists($field, $row)) $detail[$field] = (string)$row[$field];
                }
                $detail['device_response_middle'] = $row;
                foreach (['register_time', 'deregister_time', 'description', 'vendor'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $value = $this->nullText($row[$field]);
                        if ($value !== null) $detail[$field] = $value;
                    }
                }
                foreach (['deregister_count', 'running_time'] as $field) {
                    if (array_key_exists($field, $row) && $row[$field] !== null) $detail[$field] = (int)$row[$field];
                }
                if (($row['port_count_source'] ?? '') === 'hioso_snmp'
                    && isset($row['port_count']) && is_numeric($row['port_count']) && (int)$row['port_count'] > 0) {
                    $detail['port_count'] = (int)$row['port_count'];
                }
                if ($detail !== []) $this->mergeOnuJson($onuRowId, $userId, 'detail_json', $detail, [], 'middle');
                $this->paceDatabaseWrites(++$written);
            }
            return $written;
        });
    }

    public function updateSnmpOnuTypesFromCli(int $userId, int $oltDeviceId, array $rows): int
    {
        if ($userId < 1 || $oltDeviceId < 1 || $rows === []) return 0;
        return $this->onuWriteTransaction(function () use ($userId, $oltDeviceId, $rows): int {
            $stmt = $this->pdo->prepare(
                "UPDATE onus SET onu_type=:type WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx"
                . " AND is_present=1 AND (onu_serial IS NULL OR onu_serial='' OR UPPER(onu_serial)=:serial)"
            );
            $written = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $ref = trim((string)($row['onu_index'] ?? ''));
                $type = trim((string)($row['onu_type'] ?? ''));
                $serial = strtoupper(trim((string)($row['assignment_serial'] ?? '')));
                if ($ref === '' || $type === '' || $serial === '') continue;
                $this->executeInventoryWrite($stmt, [
                    'type'=>mb_substr($type, 0, 255), 'uid'=>$userId, 'dev'=>$oltDeviceId,
                    'idx'=>$ref, 'serial'=>$serial,
                ]);
                $written += $stmt->rowCount();
            }
            return $written;
        });
    }

    public function updateSnmpOnuName(int $userId, int $rowId, string $name): bool
    {
        $name = trim($name);
        $before = $this->getOnuByRowId($userId, $rowId);
        if ($before === null || (string)($before['source'] ?? '') !== 'snmp') return false;
        if (!$this->different($before['onu_name'] ?? null, $name)) return true;

        return $this->onuWriteTransaction(function () use ($userId, $rowId, $name, $before): bool {
            $stmt = $this->pdo->prepare("UPDATE onus SET onu_name=:name WHERE id=:id AND user_id=:uid");
            $this->executeInventoryWrite($stmt, ['name'=>$name,'id'=>$rowId,'uid'=>$userId]);
            if ($stmt->rowCount() !== 1) return false;

            $this->insertChangeEvent(
                $userId,
                'ONU',
                $this->onuIdentity('snmp', (int)$before['olt_id'], (string)$before['onu_index']),
                'onu_name',
                $before['onu_name'] ?? null,
                $name,
                'Nama ONU berhasil diubah dari ' . display_onu_name($before['onu_name'] ?? null)
                    . ' ke ' . display_onu_name($name) . '.',
                [
                    'source' => 'snmp',
                    'olt_id' => (int)$before['olt_id'],
                    'onu_index' => (string)$before['onu_index'],
                    'onu_name' => $name,
                    'onu_serial' => $before['onu_serial'] ?? null,
                    'onu_location' => $before['onu_location'] ?? null,
                ]
            );
            return true;
        });
    }

    
    public function updateSnmpOnuAuthMode(int $userId, int $rowId, string $authMode): bool
    {
        $authMode = trim($authMode);
        if (!in_array($authMode, ['Undef', 'Pass', 'Deny', 'uncfg'], true)) return false;
        $before = $this->getOnuByRowId($userId, $rowId);
        if ($before === null || !monitoring_row_uses_handler($before, 'snmp_olt')) return false;
        $oldAuthMode = trim((string)($before['auth_mode'] ?? ''));
        $authConfirmed = in_array($authMode, ['Pass', 'Deny'], true) ? 1 : 0;
        $oldAuthConfirmed = (int)($before['auth_confirmed_once'] ?? 0);
        $authChanged = strcasecmp($oldAuthMode, $authMode) !== 0;
        if (!$authChanged && $oldAuthConfirmed === $authConfirmed) return true;

        return $this->onuWriteTransaction(function () use ($userId, $rowId, $authMode, $before, $oldAuthMode, $authChanged, $authConfirmed): bool {
            $stmt = $this->pdo->prepare(
                "UPDATE onus SET auth_mode=:auth,auth_confirmed_once=:auth_confirmed
                 WHERE id=:id AND user_id=:uid"
            );
            $this->executeInventoryWrite($stmt, [
                'auth' => $authMode,
                'auth_confirmed' => $authConfirmed,
                'id' => $rowId,
                'uid' => $userId,
            ]);
            if ($stmt->rowCount() !== 1) return false;
            if (!$authChanged) return true;
            $this->insertChangeEvent(
                $userId,
                'ONU',
                $this->onuIdentity((string)($before['source'] ?? 'snmp'), (int)$before['olt_id'], (string)$before['onu_index']),
                'auth_mode',
                $oldAuthMode !== '' ? $oldAuthMode : null,
                $authMode,
                'Status autentikasi ONU ' . display_onu_name($before['onu_name'] ?? null)
                    . ' diubah dari ' . display_text($oldAuthMode !== '' ? $oldAuthMode : null)
                    . ' ke ' . display_text($authMode),
                [
                    'source' => (string)($before['source'] ?? 'snmp'),
                    'olt_id' => (int)$before['olt_id'],
                    'onu_index' => (string)$before['onu_index'],
                    'onu_name' => $before['onu_name'] ?? null,
                    'onu_serial' => $before['onu_serial'] ?? null,
                    'onu_location' => $before['onu_location'] ?? null,
                ]
            );
            return true;
        });
    }

    public function getOnus(int $userId, ?int $oltId = null, bool $presentOnly = true, ?string $source = null): array
    {
        $sql = "SELECT n.*,o.source,o.olt_id,o.type,SUBSTRING_INDEX(n.onu_index,':',1) physical,o.name olt_name FROM onus n INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt' WHERE n.user_id=:uid";
        $params = ['uid' => $userId];
        if ($oltId !== null) { $sql .= ' AND o.olt_id=:oid'; $params['oid'] = $oltId; }
        if ($source !== null) { $sql .= ' AND o.source=:source'; $params['source'] = $source; }
        if ($presentOnly) $sql .= ' AND n.is_present=1';
        $sql .= ' ORDER BY o.olt_id,n.id';

        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, $params);

        $rows = $stmt->fetchAll() ?: [];

        usort(
            $rows,
            fn(array $left, array $right): int =>
                $this->compareOnuRowsForDisplay($left, $right)
        );

        return $rows;
    }

    private function compareOnuRowsForDisplay(
        array $left,
        array $right
    ): int {
        $leftUndef = in_array(trim((string)($left['auth_mode'] ?? '')), ['', 'Undef', 'uncfg'], true);
        $rightUndef = in_array(trim((string)($right['auth_mode'] ?? '')), ['', 'Undef', 'uncfg'], true);

        if ($leftUndef !== $rightUndef) {
            return $leftUndef ? -1 : 1;
        }

        $oltCompare =
            ((int)($left['olt_id'] ?? 0)) <=>
            ((int)($right['olt_id'] ?? 0));

        if ($oltCompare !== 0) {
            return $oltCompare;
        }
        $sourceCompare = strcasecmp(
            (string)($left['source'] ?? ''),
            (string)($right['source'] ?? '')
        );

        if ($sourceCompare !== 0) {
            return $sourceCompare;
        }

        $indexCompare = strnatcasecmp(
            trim((string)($left['onu_index'] ?? '')),
            trim((string)($right['onu_index'] ?? ''))
        );

        if ($indexCompare !== 0) {
            return $indexCompare;
        }
        return
            ((int)($left['id'] ?? 0)) <=>
            ((int)($right['id'] ?? 0));
    }

    public function getOnu(int $userId, int $oltId, string $onuIndex, string $source = 'api'): ?array
    {
        $stmt = $this->pdo->prepare("SELECT n.*,o.source,o.olt_id,SUBSTRING_INDEX(n.onu_index,':',1) physical FROM onus n INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt' WHERE n.user_id=:uid AND o.source=:source AND o.olt_id=:oid AND n.onu_index=:idx LIMIT 1");
        $this->executeInventoryWrite($stmt, ['uid'=>$userId,'source'=>$source,'oid'=>$oltId,'idx'=>$onuIndex]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function moveOnuIndex(int $userId, int $onuRowId, int $oltDeviceId, string $oldIndex, string $newIndex): bool
    {
        if ($userId < 1 || $onuRowId < 1 || $oltDeviceId < 1 || $oldIndex === '' || $newIndex === '') return false;
        if ($oldIndex === $newIndex) return true;
        $outerTransaction = $this->pdo->inTransaction();
        try {
            return $this->onuWriteTransaction(function () use ($userId, $onuRowId, $oltDeviceId, $oldIndex, $newIndex): bool {
                $collision = $this->pdo->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1 FOR UPDATE');
                $collision->execute(['uid' => $userId, 'dev' => $oltDeviceId, 'idx' => $newIndex]);
                if ($collision->fetchColumn()) return false;
                $snapshotCollision = $this->pdo->prepare('SELECT id FROM onu_service_configs WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1 FOR UPDATE');
                $snapshotCollision->execute(['uid' => $userId, 'dev' => $oltDeviceId, 'idx' => $newIndex]);
                if ($snapshotCollision->fetchColumn()) return false;
                $move = $this->pdo->prepare('UPDATE onus SET onu_index=:new_idx WHERE id=:row_id AND user_id=:uid AND olt_device_id=:dev AND onu_index=:old_idx');
                $move->execute(['new_idx' => $newIndex, 'row_id' => $onuRowId, 'uid' => $userId, 'dev' => $oltDeviceId, 'old_idx' => $oldIndex]);
                if ($move->rowCount() !== 1) return false;
                $snapshot = $this->pdo->prepare('UPDATE onu_service_configs SET onu_index=:new_idx WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:old_idx');
                $snapshot->execute(['new_idx' => $newIndex, 'uid' => $userId, 'dev' => $oltDeviceId, 'old_idx' => $oldIndex]);
                return true;
            });
        } catch (Throwable $error) {
            if ($outerTransaction) throw $error;
            error_log('ONU index move failed: ' . $error->getMessage());
            return false;
        }
    }

    public function getAvailableOnuTypes(int $userId, int $oltDeviceId, string $ponType = 'gpon'): array
    {
        $names = [];
        foreach ($this->getOltOnuTypes($userId, $oltDeviceId) as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $kind = strtolower(trim((string)($data['pon_type'] ?? ($data['fields']['pon type'] ?? ''))));
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '' && ($kind === '' || $kind === $ponType)) $names[$name] = $name;
        }
        return array_values($names);
    }

    public function updateOnuType(int $userId, int $rowId, string $type): bool
    {
        return $this->pdo->prepare('UPDATE onus SET onu_type=:type WHERE id=:id AND user_id=:uid')
            ->execute(['type' => $type, 'id' => $rowId, 'uid' => $userId]);
    }

    public function updateOnuName(int $userId, int $rowId, string $name): bool
    {
        $name = trim($name);
        if ($rowId < 1 || $name === '' || mb_strlen($name) > 255) return false;
        $stmt = $this->pdo->prepare('UPDATE onus SET onu_name=:name WHERE id=:id AND user_id=:uid');
        $this->executeInventoryWrite($stmt, ['name' => $name, 'id' => $rowId, 'uid' => $userId]);
        if ($stmt->rowCount() === 1) return true;
        $check = $this->pdo->prepare('SELECT 1 FROM onus WHERE id=:id AND user_id=:uid LIMIT 1');
        $this->executeInventoryWrite($check, ['id' => $rowId, 'uid' => $userId]);
        return (bool) $check->fetchColumn();
    }

    public function updateOnuLocation(int $userId, int $rowId, ?string $location): array
    {
        $location = onu_location_value($location);
        if (mb_strlen($location) > 255) {
            throw new InvalidArgumentException('Lokasi ONU maksimal 255 karakter.');
        }

        $before = $this->getOnuByRowId($userId, $rowId);
        if ($before === null) {
            return ['ok' => false, 'changed' => false, 'message' => 'ONU tidak ditemukan.'];
        }

        $stored = $location !== '' ? $location : null;
        if (!$this->different($before['onu_location'] ?? null, $stored)) {
            return ['ok' => true, 'changed' => false, 'message' => 'Tidak ada perubahan pada lokasi ONU.'];
        }

        $stmt = $this->pdo->prepare('UPDATE onus SET onu_location=:location WHERE id=:id AND user_id=:uid');
        $this->executeInventoryWrite($stmt, ['location' => $stored, 'id' => $rowId, 'uid' => $userId]);

        $after = $this->getOnuByRowId($userId, $rowId);
        if ($after === null) {
            return ['ok' => false, 'changed' => false, 'message' => 'ONU tidak ditemukan setelah pembaruan.'];
        }

        $context = [
            'source' => $after['source'] ?? $before['source'] ?? null,
            'olt_id' => (int)($before['olt_id'] ?? 0),
            'onu_index' => (string)($before['onu_index'] ?? ''),
            'onu_name' => $after['onu_name'] ?? $before['onu_name'] ?? null,
        ];
        $key = (string)($context['source'] ?? '') . '|' . (int)($before['olt_id'] ?? 0) . '|' . (string)($before['onu_index'] ?? '');

        $this->insertChangeEvent(
            $userId,
            'ONU',
            $key,
            'onu_location',
            $before['onu_location'] ?? null,
            $after['onu_location'] ?? null,
            'Mengedit lokasi ' . display_text($before['onu_location'] ?? null)
                . ' ➠ ' . display_text($after['onu_location'] ?? null) ,
            $context
        );

        return ['ok' => true, 'changed' => true, 'message' => 'Lokasi berhasil diperbarui'];
    }

    public function restoreOnuBackupRows(int $userId, array $updates): int
    {
        if ($updates === []) return 0;

        $statement = $this->pdo->prepare(
            'UPDATE onus SET onu_name=:name,onu_location=:location ' .
            'WHERE id=:id AND user_id=:uid'
        );
        return $this->onuWriteTransaction(function () use ($userId, $updates, $statement): int {
            $updated = 0;
            foreach ($updates as $row) {
                $rowId = (int)($row['id'] ?? 0);
                $name = trim((string)($row['name'] ?? ''));
                $address = trim((string)($row['address'] ?? ''));
                if ($rowId < 1 || mb_strlen($name) > 255 || mb_strlen($address) > 255) {
                    throw new InvalidArgumentException('Data import ONU tidak valid.');
                }
                $this->executeInventoryWrite($statement, [
                    'name' => $name !== '' ? $name : null,
                    'location' => $address !== '' ? $address : null,
                    'id' => $rowId,
                    'uid' => $userId,
                ]);
                if ($statement->rowCount() === 1) $updated++;
            }
            return $updated;
        });
    }

    public function getOnuByRowId(int $userId, int $rowId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.*,o.source,o.olt_id,SUBSTRING_INDEX(n.onu_index,':',1) physical,
                    o.id olt_row_id,
                    o.name olt_name,
                    o.ip_address olt_ip_address,
                    o.snmp_port,
                    o.type,
                    o.snmp_community,
                    st.driver_status
             FROM onus n
             LEFT JOIN devices o
               ON o.user_id=n.user_id
              AND o.device_type='olt'
              AND o.id=n.olt_device_id
             LEFT JOIN device_status st
               ON st.device_id=o.id
             WHERE n.user_id=:uid AND n.id=:id
             LIMIT 1"
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'id' => $rowId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function saveOnuSerialIfMissing(int $userId, int $rowId, string $serial): bool
    {
        $serial = trim($serial);
        if ($userId < 1 || $rowId < 1 || $serial === '' || mb_strlen($serial) > 255) return false;
        $stmt = $this->pdo->prepare(
            "UPDATE onus SET onu_serial=:serial WHERE user_id=:uid AND id=:id AND (onu_serial IS NULL OR onu_serial='')"
        );
        $this->executeInventoryWrite($stmt, ['serial' => $serial, 'uid' => $userId, 'id' => $rowId]);
        $row = $this->getOnuByRowId($userId, $rowId);
        return is_array($row) && trim((string)($row['onu_serial'] ?? '')) !== '';
    }

    public function deleteSnmpOnuSnapshot(int $userId, int $rowId): bool
    {
        $onu = $this->getOnuByRowId($userId, $rowId);
        if ($onu === null || (string) ($onu['source'] ?? '') !== 'snmp') {
            return false;
        }

        return $this->onuWriteTransaction(function () use ($userId, $rowId, $onu): bool {
            $this->insertChangeEvent(
                $userId,
                'ONU',
                'snmp|' . (int)$onu['olt_id'] . '|' . (string)$onu['onu_index'],
                'deleted',
                $onu['onu_name'] ?? null,
                '1',
                'ONU ' . display_onu_name($onu['onu_name'] ?? null) . ' berhasil dihapus.',
                [
                    'source' => 'snmp',
                    'olt_id' => (int)$onu['olt_id'],
                    'onu_index' => (string)$onu['onu_index'],
                    'onu_name' => $onu['onu_name'] ?? null,
                    'onu_serial' => $onu['onu_serial'] ?? null,
                    'onu_location' => $onu['onu_location'] ?? null,
                ]
            );

            $delete = $this->pdo->prepare(
                "DELETE FROM onus
                 WHERE id=:id AND user_id=:uid"
            );
            $this->executeInventoryWrite($delete, ['id' => $rowId, 'uid' => $userId]);

            $count = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM onus
                 WHERE user_id=:uid AND olt_device_id=:olt_device_id AND is_present=1"
            );
            $this->executeInventoryWrite($count, [
                'uid' => $userId,
                'olt_device_id' => (int) $onu['olt_device_id'],
            ]);

            $this->saveDeviceTotalOnus((int)$onu['olt_device_id'], $userId, (int)$count->fetchColumn());

            return $delete->rowCount() === 1;
        });
    }

    public function getProblemOnus(int $userId, int $limit = 200, bool $includeOfflineDuration = false): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare("SELECT * FROM change_events WHERE user_id=:uid AND entity_type='ONU' AND field_name IN ('onu_state','deleted') ORDER BY created_at DESC,id DESC");
        $this->executeInventoryWrite($stmt, ['uid' => $userId]);
        $events = $stmt->fetchAll() ?: [];

        $latestChanges = [];
        foreach ($events as $event) {
            $identity = $this->changeEventOnuIdentity($event);
            if ($identity === '' || isset($latestChanges[$identity])) continue;
            $latestChanges[$identity] = $event;
        }

        $problemSettings = $this->getOnuProblemSettings($userId);
        $rxProblemThreshold = (float)$problemSettings['rx_power_threshold_dbm'];
        $rxProblemConfirmations = [];
        $rxProblemStmt = $this->pdo->prepare(
            "SELECT entity.entity_key,state.confirmed_at
             FROM sync_state state
             INNER JOIN sync_entitas entity ON entity.id=state.monitor_entity_id
             WHERE entity.user_id=:uid AND entity.entity_type='ONU'
               AND state.field_name='rx_problem_state' AND state.confirmed_value='problem'"
        );
        $this->executeInventoryWrite($rxProblemStmt, ['uid'=>$userId]);
        foreach ($rxProblemStmt->fetchAll() ?: [] as $confirmation) {
            $rxProblemConfirmations[(string)$confirmation['entity_key']] = (string)($confirmation['confirmed_at'] ?? '');
        }

        $stateConfirmations = [];
        $stateConfirmationStmt = $this->pdo->prepare(
            "SELECT entity.entity_key,state.confirmed_value,state.confirmed_at
             FROM sync_state state
             INNER JOIN sync_entitas entity ON entity.id=state.monitor_entity_id
             WHERE entity.user_id=:uid AND entity.entity_type='ONU' AND state.field_name='onu_state'"
        );
        $this->executeInventoryWrite($stateConfirmationStmt, ['uid'=>$userId]);
        foreach ($stateConfirmationStmt->fetchAll() ?: [] as $confirmation) {
            $stateConfirmations[(string)$confirmation['entity_key']] = $confirmation;
        }

        $cutoff = time() - (self::ONU_PROBLEM_AFTER_HOURS * 3600);
        $allOnus = $this->getOnus($userId, null, true, null);
        $onuMap = [];
        foreach ($allOnus as $onu) {
            $identity = $this->onuIdentity((string)($onu['source'] ?? ''), (int)($onu['olt_id'] ?? 0), (string)($onu['onu_index'] ?? ''));
            if ($identity === '') continue;
            $onuMap[$identity] = $onu;
        }

        $rows = [];
        foreach ($onuMap as $identity => $onu) {
            $rxPower = onu_rx_power_number($onu['rx_power'] ?? null);
            $rxProblemConfirmedAt = $rxProblemConfirmations[$identity] ?? null;
            if (
                $rxPower !== null
                && is_string($rxProblemConfirmedAt)
                && strtotime($rxProblemConfirmedAt) !== false
            ) {
                $onu['problem_since'] = $rxProblemConfirmedAt;
                $onu['problem_status'] = 'RX Power';
                $onu['problem_reason'] = 'rx_power';
                $onu['problem_description'] = 'RX Power ' . format_power($rxPower) . ', Bermasalah';
                $onu['problem_rx_power_threshold_dbm'] = $rxProblemThreshold;
                $rows[] = $onu;
                continue;
            }

            if ($includeOfflineDuration) {
            $currentKey = effective_onu_state_key(
                $onu['onu_state'] ?? null,
                $onu['rx_power'] ?? null,
                onu_loss_without_rx($onu)
            );
            if (!in_array($currentKey, ['offline', 'loss', 'powerdown'], true)) continue;

            $onuCreatedAt = (string)($onu['created_at'] ?? '');
            $onuCreatedTimestamp = strtotime($onuCreatedAt);
            $stateConfirmation = $stateConfirmations[$identity] ?? null;
            if (is_array($stateConfirmation)) {
             $confirmedKey = state_key($stateConfirmation['confirmed_value'] ?? null);
                if (!in_array($confirmedKey, ['offline', 'loss', 'powerdown'], true)) continue;
            }

            $confirmedAt = is_array($stateConfirmation)
                ? (string)($stateConfirmation['confirmed_at'] ?? '')
                : '';
            $confirmedTimestamp = strtotime($confirmedAt);
            $changedAt = $confirmedTimestamp !== false ? $confirmedAt : $onuCreatedAt;
            $changedTimestamp = $confirmedTimestamp !== false ? $confirmedTimestamp : $onuCreatedTimestamp;
            $event = $latestChanges[$identity] ?? null;

            if (is_array($event)) {
                $eventChangedAt = (string)($event['created_at'] ?? '');
                $eventChangedTimestamp = strtotime($eventChangedAt);
                $eventBelongsToCurrentLifecycle = $eventChangedTimestamp !== false
                    && ($onuCreatedTimestamp === false || $eventChangedTimestamp >= $onuCreatedTimestamp);

                if ($eventBelongsToCurrentLifecycle) {
                   if ((string)($event['field_name'] ?? '') === 'deleted') continue;
                    $eventKey = state_key($event['new_value'] ?? null);
                    if (!in_array($eventKey, ['offline', 'loss', 'powerdown'], true)) continue;
                    $changedAt = $eventChangedAt;
                    $changedTimestamp = $eventChangedTimestamp;
                }
            }
            if ($changedTimestamp === false || $changedTimestamp > $cutoff) continue;

            $onu['problem_since'] = $changedAt;
            $onu['problem_status'] = state_label($currentKey);
            $onu['problem_reason'] = 'offline_duration';
            $onu['problem_description'] = 'Offline lebih dari ' . self::ONU_PROBLEM_AFTER_HOURS . ' jam';
            $rows[] = $onu;
        }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['problem_since'] ?? ''), (string)($b['problem_since'] ?? '')));
        return array_slice($rows, 0, $limit);
    }

    public function recordOverdueOnuProblemEvents(int $userId): int
    {
        $problems = $this->getProblemOnus($userId, 1000);
        if ($problems === []) return 0;

        $findEscalation = $this->pdo->prepare(
            "SELECT id FROM change_events
             WHERE user_id=:uid AND entity_type='ONU' AND entity_key=:entity_key
               AND field_name='problem_offline' AND created_at>=:problem_since
             LIMIT 1"
        );

        $created = 0;
        foreach ($problems as $onu) {
            if ((string)($onu['problem_reason'] ?? '') !== 'offline_duration') continue;
            $source = trim((string)($onu['source'] ?? ''));
            $oltId = (int)($onu['olt_id'] ?? 0);
            $index = trim((string)($onu['onu_index'] ?? ''));
            $identity = $this->onuIdentity($source, $oltId, $index);
            if ($identity === '') continue;
            $problemSince = (string)($onu['problem_since'] ?? '');
            if (strtotime($problemSince) === false) continue;
            $this->executeInventoryWrite($findEscalation, [
                'uid' => $userId,
                'entity_key' => $identity,
                'problem_since' => $problemSince,
            ]);
            if ($findEscalation->fetchColumn() !== false) continue;

            $oltName = display_text($onu['olt_name'] ?? $onu['device_name'] ?? null);
            $onuName = display_onu_name($onu['onu_name'] ?? null);
            $message = 'Offline lebih dari '
                . self::ONU_PROBLEM_AFTER_HOURS . ' jam '
                . $oltName . ' ' . $index . ' ' . $onuName . '.';
            $this->insertChangeEvent(
                $userId,
                'ONU',
                $identity,
                'problem_offline',
                $problemSince,
                self::ONU_PROBLEM_AFTER_HOURS,
                $message,
                [
                    'source' => $source,
                    'olt_id' => $oltId,
                    'onu_index' => $index,
                    'onu_name' => $onu['onu_name'] ?? null,
                    'onu_serial' => $onu['onu_serial'] ?? null,
                    'onu_location' => $onu['onu_location'] ?? null,
                ]
            );
            $created++;
        }
        return $created;
    }

    public function getRecentOnuActivityEvents(int $userId, int $limit = 50, int $hours = 24, int $offset = 0, string $search = '', string $device = ''): array
    {
        $limit = max(1, min(100, $limit));
        $hours = max(1, min(24, $hours));
        $offset = max(0, $offset);
        $filter = $this->recentActivityFilter($userId, $hours, $search, $device);
        $sql = "SELECT e.*,o.name AS olt_name,n.onu_name AS current_onu_name,n.onu_location AS current_onu_location,n.onu_serial AS current_onu_serial
                FROM change_events e
                LEFT JOIN devices o ON o.user_id=e.user_id AND o.device_type='olt' AND o.olt_id=e.event_olt_id AND (e.event_source IS NULL OR e.event_source='' OR o.source=e.event_source)
                LEFT JOIN onus n ON n.olt_device_id=o.id AND n.onu_index=e.event_onu_index
                WHERE {$filter['sql']}
                ORDER BY e.created_at DESC,e.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $this->executeInventoryWrite($stmt, $filter['params']);
        return $this->decorateChangeEvents($stmt->fetchAll() ?: [], $userId);
    }

    public function countRecentOnuActivityEvents(int $userId, int $hours = 24, string $search = '', string $device = ''): int
    {
        $hours = max(1, min(24, $hours));
        $filter = $this->recentActivityFilter($userId, $hours, $search, $device);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM change_events e WHERE {$filter['sql']}");
        $this->executeInventoryWrite($stmt, $filter['params']);
        return (int)$stmt->fetchColumn();
    }

    private function normalizeOnuState(mixed $state, mixed $rxPower, array $context = []): ?string
    {
        $rawState = trim((string)$state);
        return $rawState !== '' ? $rawState : null;
    }

    private function displayOnuState(mixed $state): string
    {
        return match (state_key($state)) {
            'online' => 'Online',
            'loss' => 'Lost',
            'powerdown' => 'PowerDown',
            'offline' => 'Down',
            default => display_text($state),
        };
    }

    
    private function snmpOnuSerialValue(array $row): string
    {
        foreach (['serial', 'onu_serial', 'mac'] as $key) {
            if (!array_key_exists($key, $row)) continue;
            $value = $this->nullText($row[$key]);
            if ($value !== null && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        }
        return '';
    }

    private function onuIdentity(string $source, int $oltId, string $onuIndex): string
    {
        if ($oltId < 1 || trim($onuIndex) === '') return '';
        return strtolower(trim($source)) . '|' . $oltId . '|' . trim($onuIndex);
    }

    private function usableSyncedOnuName(mixed $value): ?string
    {
        $name = $this->nullText($value);
        if ($name === null || mb_strlen($name) <= 5) return null;
        $normalized = strtoupper(preg_replace('/[\s#._\/-]+/', '', $name) ?? '');
        return in_array($normalized, ['NA', 'NAN', 'NULL', 'UNKNOWN', 'NONAME'], true)
            || preg_match('/^(NA)+$/', $normalized) === 1
            ? null
            : $name;
    }

    
    public function saveOnuServiceConfigs(int $userId, int $oltDeviceId, array $configs, string $source = 'fast'): int
    {
        if ($configs === [] || $oltDeviceId < 1) return 0;
        $source = in_array($source, ['fast', 'discovery', 'action'], true) ? $source : 'fast';
        $savedTemplates = [];
        $templateParams = ['uid' => $userId, 'dev' => $oltDeviceId];
        $singleRef = count($configs) === 1 ? trim((string)array_key_first($configs)) : '';
        $templateSql = "SELECT onu_index,config_json FROM onu_service_configs WHERE user_id=:uid AND olt_device_id=:dev AND config_json LIKE '%\"service_templates\"%'";
        if ($singleRef !== '') {
            $templateSql .= ' AND onu_index=:ref';
            $templateParams['ref'] = $singleRef;
        }
        $templateStmt = $this->pdo->prepare($templateSql);
        $this->executeInventoryWrite($templateStmt, $templateParams);
        foreach ($templateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $savedRow) {
            $savedConfig = json_decode((string)($savedRow['config_json'] ?? ''), true);
            if (is_array($savedConfig['service_templates'] ?? null)) {
                $savedTemplates[(string)$savedRow['onu_index']] = $savedConfig['service_templates'];
            }
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO onu_service_configs(user_id,olt_device_id,onu_index,service_name,vlan,tcont_index,tcont_profile,traffic_profile,config_json,source,synced_at)
             VALUES (:uid,:olt_device_id,:onu_index,:service_name,:vlan,:tcont_index,:tcont_profile,:traffic_profile,:config_json,:source,NOW())
             ON DUPLICATE KEY UPDATE service_name=VALUES(service_name),vlan=VALUES(vlan),tcont_index=VALUES(tcont_index),
                tcont_profile=VALUES(tcont_profile),traffic_profile=VALUES(traffic_profile),
                config_json=VALUES(config_json),source=VALUES(source),synced_at=NOW()"
        );
        return $this->onuWriteTransaction(function () use ($configs, $savedTemplates, $stmt, $userId, $oltDeviceId, $source): int {
            $written = 0;
            foreach ($configs as $ref => $config) {
                if (!is_array($config)) continue;
                $onuIndex = trim((string)($ref !== '' ? $ref : ($config['ref'] ?? '')));
                if ($onuIndex === '') continue;
                if (!array_key_exists('service_templates', $config) && isset($savedTemplates[$onuIndex])) {
                    $config['service_templates'] = $savedTemplates[$onuIndex];
                }
                $tcont = (array)($config['tcont'] ?? []);
                $gemport = (array)($config['gemport'] ?? []);
                $servicePort = (array)(($config['service_ports'] ?? [])[0] ?? []);
                $tcontFirst = (array)($tcont[0] ?? []);
                $gemportFirst = (array)($gemport[0] ?? []);
                $encoded = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->executeInventoryWrite($stmt, [
                    'uid' => $userId,
                    'olt_device_id' => $oltDeviceId,
                    'onu_index' => $onuIndex,
                    'service_name' => (string)($tcontFirst['name'] ?? ''),
                    'vlan' => isset($servicePort['vlan']) ? (int)$servicePort['vlan'] : null,
                    'tcont_index' => isset($tcontFirst['index']) ? (int)$tcontFirst['index'] : null,
                    'tcont_profile' => (string)($tcontFirst['profile'] ?? ''),
                    'traffic_profile' => (string)($gemportFirst['traffic_limit'] ?? ''),
                    'config_json' => is_string($encoded) ? $encoded : null,
                    'source' => $source,
                ]);
                $written++;
            }
            return $written;
        });
    }

    
    private function mergeOnuJson(int $onuRowId, int $userId, string $column, array $updates, array $clearKeys = [], ?string $phase = null): bool
    {
        if (!in_array($column, ['detail_json', 'port_lan_json'], true)) throw new InvalidArgumentException('Kolom data ONU tidak valid.');
        if ($phase !== null) {
            if ($column !== 'detail_json') throw new InvalidArgumentException('Fase detail ONU tidak valid.');
            $owned = match ($phase) {
                'fast' => ['temperature', 'rx_power_raw', 'temperature_raw', 'device_response_fast'],
                'middle' => ['tx_power', 'distance', 'olt_rx_power', 'tx_power_raw', 'olt_rx_power_raw', 'register_time', 'deregister_time', 'description', 'vendor', 'deregister_count', 'running_time', 'port_count', 'device_response_middle'],
                'low' => ['port_count', 'online_duration', 'device_response_low'],
                default => throw new InvalidArgumentException('Fase detail ONU tidak valid.'),
            };
            $updates = array_intersect_key($updates, array_flip($owned));
            $clearKeys = array_values(array_intersect($clearKeys, $owned));
        }
        $setParts = [];
        $params = ['id' => $onuRowId, 'uid' => $userId];
        foreach ($updates as $key => $value) {
            if ($value === null && !in_array($key, $clearKeys, true)) continue;
            if (preg_match('/^[a-z][a-z0-9_]*$/D', (string)$key) !== 1) throw new InvalidArgumentException('Nama field ONU tidak valid.');
            $param = 'json_value_' . count($setParts);
            $setParts[] = "'$." . $key . "',JSON_EXTRACT(:" . $param . ",'$')";
            $params[$param] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($setParts === []) return false;
        $sql = 'UPDATE onus SET ' . $column . '=JSON_SET(COALESCE(' . $column . ",'{}')," . implode(',', $setParts) . ') WHERE id=:id AND user_id=:uid';
        return $this->executeInventoryWrite($this->pdo->prepare($sql), $params);
    }

    private function clearRejectedOnuRx(int $onuRowId, int $userId): void
    {
        $paths = ['$.rx_power', '$.rx_power_raw', '$.rx_power_device_raw'];
        foreach (['device_response', 'device_response_fast', 'device_response_middle', 'device_response_optical', 'device_response_low'] as $source) {
            foreach (['rx_power', 'rx_power_raw', 'rx_power_device_raw', 'device_raw.rx_power', 'optical_patch.rx_power', 'optical_patch.raw', 'optics.rx_power', 'snapshot.rx_power', 'raw', 'output'] as $field) {
                $paths[] = '$.' . $source . '.' . $field;
            }
        }
        $quoted = implode(',', array_map(fn(string $path): string => $this->pdo->quote($path), $paths));
        $sql = "UPDATE onus SET rx_power=NULL, detail_json=JSON_REMOVE(COALESCE(detail_json,'{}'),{$quoted}) WHERE id=:id AND user_id=:uid";
        $this->executeInventoryWrite($this->pdo->prepare($sql), ['id' => $onuRowId, 'uid' => $userId]);
    }

    public function saveOnuDetailSnapshots(int $userId, int $oltDeviceId, array $details): int
    {
        if ($details === [] || $oltDeviceId < 1) return 0;
        $idStmt = $this->pdo->prepare('SELECT id FROM onus WHERE user_id=:uid AND olt_device_id=:dev AND onu_index=:idx LIMIT 1');
        $typeStmt = $this->pdo->prepare('UPDATE onus SET actual_type=:value WHERE id=:id AND user_id=:uid');
        $written = 0;
        foreach ($details as $ref => $detail) {
            if (!is_array($detail)) continue;
            $rxRejected = false;
            $detail = onu_strip_rejected_rx($detail, $rxRejected);
            $onuIndex = trim((string)$ref);
            if ($onuIndex === '') continue;
            $this->executeInventoryWrite($idStmt, ['uid' => $userId, 'dev' => $oltDeviceId, 'idx' => $onuIndex]);
            $onuRowId = (int)$idStmt->fetchColumn();
            if ($onuRowId < 1) continue;
            $fields = (array)($detail['fields'] ?? []);
            $onlineDuration = null;
            foreach (['online duration', 'online-duration', 'online_duration', 'running time', 'running_time'] as $runtimeKey) {
                $runtime = trim((string)($fields[$runtimeKey] ?? $detail[$runtimeKey] ?? ''));
                if ($runtime !== '') {
                    $onlineDuration = mb_substr($runtime, 0, 64);
                    break;
                }
            }
            $equipmentId = null;
            foreach (['equipment id', 'equipment-id', 'equipmentid', 'equipment'] as $equipmentKey) {
                $equipmentValue = trim((string)($fields[$equipmentKey] ?? ''));
                if ($equipmentValue !== '') { $equipmentId = mb_substr($equipmentValue, 0, 64); break; }
            }
            $this->mergeOnuJson($onuRowId, $userId, 'detail_json', [
                'online_duration' => $onlineDuration,
                'device_response_low' => $detail,
            ], [], 'low');
            if ($equipmentId !== null) {
                $this->executeInventoryWrite($typeStmt, ['value' => $equipmentId, 'id' => $onuRowId, 'uid' => $userId]);
            }
            $written++;
        }
        return $written;
    }

    
    public function getOnuServiceConfig(int $userId, int $oltDeviceId, string $onuIndex): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM onu_service_configs WHERE user_id=:uid AND olt_device_id=:olt_device_id AND onu_index=:onu_index LIMIT 1'
        );
        $this->executeInventoryWrite($stmt, ['uid' => $userId, 'olt_device_id' => $oltDeviceId, 'onu_index' => trim($onuIndex)]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        $decoded = json_decode((string)($row['config_json'] ?? ''), true);
        $row['config'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    private function syncedOnuNameFromItem(array $item): ?string
    {
        foreach (['onu_name', 'ont_name', 'name'] as $field) {
            $name = $this->usableSyncedOnuName($item[$field] ?? null);
            if ($name !== null) return $name;
        }
        return null;
    }

    public function countUnregisteredOnus(int $userId, string $search = ''): int
    {
        if ($userId < 1) return 0;
        $searchSql = $search !== '' ? " AND CONCAT_WS(' ',n.onu_serial,n.onu_index,n.onu_name,n.onu_location,o.name,o.ip_address) LIKE :search" : '';
        $params = ['uid' => $userId];
        if ($search !== '') $params['search'] = '%' . $search . '%';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM onus n INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt' WHERE n.user_id=:uid AND COALESCE(NULLIF(n.auth_mode,''),'Undef') IN ('Undef','uncfg') AND n.is_present=1{$searchSql}"
        );
        $this->executeInventoryWrite($stmt, $params);
        return (int)$stmt->fetchColumn();
    }


    public function getUnregisteredOnusPage(int $userId, int $limit = 20, int $offset = 0, string $search = ''): array
    {
        if ($userId < 1) return [];
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $searchSql = $search !== '' ? " AND CONCAT_WS(' ',n.onu_serial,n.onu_index,n.onu_name,n.onu_location,o.name,o.ip_address) LIKE :search" : '';
        $params = ['uid' => $userId];
        if ($search !== '') $params['search'] = '%' . $search . '%';
        $stmt = $this->pdo->prepare(
            "SELECT n.*,o.source,o.olt_id,o.type,SUBSTRING_INDEX(n.onu_index,':',1) physical,o.name olt_name
             FROM onus n
             INNER JOIN devices o ON o.id=n.olt_device_id AND o.user_id=n.user_id AND o.device_type='olt'
             WHERE n.user_id=:uid AND COALESCE(NULLIF(n.auth_mode,''),'Undef') IN ('Undef','uncfg') AND n.is_present=1{$searchSql}
             ORDER BY o.olt_id,n.id
             LIMIT {$limit} OFFSET {$offset}"
        );
        $this->executeInventoryWrite($stmt, $params);
        return $stmt->fetchAll() ?: [];
    }
}
