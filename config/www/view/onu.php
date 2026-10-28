<?php

$onuDetailViewModelPath = project_path('view', 'shared', 'models', 'OltDetailViewModel.php');
if (is_file($onuDetailViewModelPath)) require_once $onuDetailViewModelPath;
require_once __DIR__ . '/partials/onu/view.php';
require_once LINNMS_UI_ROOT . '/controllers/olt/view-onu.php';

$onuReadData = is_array($onuDetail ?? null)
    ? onu_view_read_data((int)current_user_id(), (int)$onuDetail['id'])
    : [];
$onuView = is_array($onuDetail ?? null)
    ? onu_detail_view_model($onuReadData, $onuDetail, is_array($onuLive ?? null) ? $onuLive : [], $onuSnmpDetail ?? null, [], $onuIsProblem ?? false, $onuProblemDescription ?? null)
    : ['ok' => false];
$onuViewCapabilities = is_array($onuReadData['capabilities'] ?? null) ? $onuReadData['capabilities'] : [];
$onuServiceVlanNames = is_array($onuReadData['vlan_names'] ?? null) ? $onuReadData['vlan_names'] : [];
$onuServiceTcontProfiles = is_array($onuReadData['tcont_profiles'] ?? null) ? $onuReadData['tcont_profiles'] : [];
$onuServiceTrafficProfiles = is_array($onuReadData['traffic_profiles'] ?? null) ? $onuReadData['traffic_profiles'] : [];
$onuServiceVlans = is_array($onuReadData['vlan_ids'] ?? null) ? $onuReadData['vlan_ids'] : [];
require __DIR__ . '/partials/onu/body.php';
