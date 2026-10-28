<?php

declare(strict_types=1);

final class AddOnuReadService
{
    public function __construct(private LinnmsRepository $db)
    {
    }

    public function read(int $userId): array
    {
        if ($userId < 1) throw new InvalidArgumentException('User API internal tidak valid.');
        $olts = $this->db->getOlts($userId);
        $total = $this->db->countUnregisteredOnus($userId);
        $pendingOnus = array_map(static fn(array $onu): array => [
            'id' => (int)($onu['id'] ?? 0),
            'olt_device_id' => (int)($onu['olt_device_id'] ?? 0),
            'olt_name' => (string)($onu['olt_name'] ?? ''),
            'onu_index' => (string)($onu['onu_index'] ?? ''),
            'onu_serial' => (string)($onu['onu_serial'] ?? ''),
            'onu_name' => (string)($onu['onu_name'] ?? ''),
            'onu_location' => (string)($onu['onu_location'] ?? ''),
            'onu_type' => (string)($onu['onu_type'] ?? ''),
            'physical' => (string)($onu['physical'] ?? ''),
        ], $this->db->getUnregisteredOnusPage($userId, max(1, $total)));
        $oltsByDeviceId = [];
        foreach ($olts as $olt) {
            if (!is_array($olt)) continue;
            $id = (int)($olt['id'] ?? 0);
            if ($id > 0) $oltsByDeviceId[$id] = $olt;
        }

        $groups = [];
        foreach ($pendingOnus as $onu) {
            if (!is_array($onu)) continue;
            $deviceId = (int)($onu['olt_device_id'] ?? 0);
            if (!isset($groups[$deviceId])) {
                $olt = $oltsByDeviceId[$deviceId] ?? [];
                $driverId = device_driver_type($olt);
                $groups[$deviceId] = [
                    'olt' => [
                        'id' => $deviceId,
                        'name' => display_text($olt['name'] ?? $onu['olt_name'] ?? null, 'OLT'),
                        'ip_address' => trim((string)($olt['ip_address'] ?? '')),
                        'type' => $driverId,
                        'driver_id' => $driverId,
                    ],
                    'onus' => [],
                ];
            }
            $groups[$deviceId]['onus'][] = $onu;
        }

        foreach ($olts as $olt) {
            if (!is_array($olt)) continue;
            $driverId = device_driver_type($olt);
            $definition = monitoring_driver_definition($driverId);
            if (empty($definition['unconfigured_refresh'])) continue;
            $deviceId = (int)($olt['id'] ?? 0);
            if ($deviceId < 1) continue;
            if (!isset($groups[$deviceId])) {
                $groups[$deviceId] = [
                    'olt' => [
                        'id' => $deviceId,
                        'name' => (string)($olt['name'] ?? 'OLT'),
                        'ip_address' => (string)($olt['ip_address'] ?? ''),
                        'type' => $driverId,
                        'driver_id' => $driverId,
                    ],
                    'onus' => [],
                ];
            }
            $groups[$deviceId]['can_refresh_unconfigured'] = true;
        }

        uasort($groups, static fn(array $left, array $right): int =>
            strnatcasecmp((string)($left['olt']['name'] ?? ''), (string)($right['olt']['name'] ?? '')));
        foreach ($groups as &$group) {
            usort($group['onus'], static fn(array $left, array $right): int =>
                strnatcasecmp((string)($left['onu_index'] ?? ''), (string)($right['onu_index'] ?? '')));
        }
        unset($group);

        $families = [];
        $familyGroups = [];
        foreach ($groups as $deviceId => $group) {
            $family = olt_page_family((string)($group['olt']['driver_id'] ?? ''));
            $groups[$deviceId]['family'] = $family;
            $familyGroups[$family][] = $groups[$deviceId];
            if ($group['onus'] !== [] || !empty($group['can_refresh_unconfigured'])) $families[$family] = true;
        }

        $modalData = [];
        foreach ($familyGroups as $family => $items) {
            $vlanByOlt = [];
            $profileByOlt = [];
            $deviceByOlt = [];
            foreach ($items as $group) {
                $oltId = (int)($group['olt']['id'] ?? 0);
                if ($oltId < 1) continue;
                $traffic = [];
                $tcont = [];
                $vlanProfiles = [];
                $ipProfiles = [];
                foreach ($this->db->getOltProfileSnapshots($userId, $oltId) as $profile) {
                    if (!is_array($profile)) continue;
                    $kind = (string)($profile['kind'] ?? '');
                    $name = trim((string)($profile['name'] ?? ''));
                    if ($name === '') continue;
                    if ($kind === 'traffic') $traffic[] = $name;
                    elseif ($kind === 'tcont') $tcont[] = $name;
                    elseif ($kind === 'vlan_profile') {
                        $data = (array)($profile['data'] ?? []);
                        $vlanProfiles[] = ['name' => $name, 'cvlan' => (int)($data['cvlan'] ?? 0)];
                    } elseif ($kind === 'ip') $ipProfiles[] = $name;
                }
                $onuTypes = [];
                foreach ($this->db->getOltOnuTypes($userId, $oltId) as $type) {
                    $name = trim((string)($type['name'] ?? ''));
                    if ($name !== '') $onuTypes[] = $name;
                }
                $vlanByOlt[$oltId] = array_values(array_unique($this->db->getOltVlanIds($userId, $oltId)));
                sort($vlanByOlt[$oltId]);
                $profileByOlt[$oltId] = [
                    'onu_type' => array_values(array_unique($onuTypes)),
                    'traffic' => array_values(array_unique($traffic)),
                    'tcont' => array_values(array_unique($tcont)),
                    'vlan_profile' => $vlanProfiles,
                    'ip_profile' => array_values(array_unique($ipProfiles)),
                ];
                $capabilities = onu_registration_capabilities_for_driver((string)($group['olt']['driver_id'] ?? ''));
                $deviceByOlt[$oltId] = [
                    'capabilities' => $capabilities,
                    'provision' => !empty($capabilities['batch_provision']),
                ];
            }
            $modalData[$family] = [
                'service_options' => olt_service_options_from_snapshots($this->db, $userId, $items),
                'vlan_by_olt' => $vlanByOlt,
                'profile_by_olt' => $profileByOlt,
                'device_by_olt' => $deviceByOlt,
            ];
        }

        return [
            'pending_onus' => $pendingOnus,
            'groups' => array_values($groups),
            'family_groups' => $familyGroups,
            'families' => array_keys($families),
            'modal_data' => $modalData,
        ];
    }
}
