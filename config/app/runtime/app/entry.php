<?php

declare(strict_types=1);

if (!function_exists('render_flash_toast')) require_once project_path('view','bootstrap.php');

$state=$bridge->state();
$requestMethod=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$op=trim((string)($_POST['action']??''));
$requestPath=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)??'/');
$basePath=deployment_base_path();
if ($basePath!=='' && str_starts_with($requestPath,$basePath.'/')) $requestPath=substr($requestPath,strlen($basePath));
$requestPath='/'.trim($requestPath,'/');
$activationUrl=public_app_url('activate');
$setupUrl=public_app_url('setup');

if ($requestMethod==='POST' && in_array($op,['access_submit','setup_submit'],true)) {
    try {
        verify_csrf();
        if ($op==='access_submit') {
            if ($requestPath!=='/activate' || !in_array((string)($state['mode']??''),['pending','fault'],true)) {
                throw new RuntimeException('Aktivasi tidak tersedia.');
            }
            $bridge->open((string)($_POST['account']??''),(string)($_POST['token']??''),$db);
            unset($_SESSION['user_id'],$_SESSION['username'],$_SESSION['display_name'],$_SESSION['role']);
            session_regenerate_id(true);
            flash('success','Aktivasi berhasil.');
            header('Location: '.public_app_url(),true,303); exit;
        }
        if ($op==='setup_submit') {
            if ($requestPath!=='/setup' || (string)($state['mode']??'')!=='ready') {
                throw new RuntimeException('Setup akun tidak tersedia.');
            }
            $userId=$bridge->createProfile($db,$_POST);
            $user=$db->getUser($userId);
            if ($user===null) throw new RuntimeException('Akun yang baru dibuat belum dapat dimuat.');
            linnms_sign_in_user($user);
            flash('success','Akun berhasil dibuat.');
            header('Location: '.public_page_url('dashboard'),true,303); exit;
        }
    } catch (Throwable $e) {
        flash('error',$e->getMessage());
        header('Location: '.($op==='access_submit'?$activationUrl:$setupUrl),true,303); exit;
    }
}

$state=$bridge->state();
$appIdentity=($state['mode']??'')==='ready'?$db->getAppIdentity():LinnmsDefaults::APP_IDENTITY;
$appName=$appIdentity['name'];
$appVersion=$appIdentity['version'];
if (in_array((string)($state['mode']??''), ['pending','fault'], true)) {
    if ($requestPath!=='/activate') { header('Location: '.$activationUrl,true,303); exit; }
    $presetUser='';
    $flashes=pull_flashes();
    require project_path('view','access.php'); exit;
}
if (($state['mode'] ?? '') === 'ended') {
    $bridge->cleanupDeadline();
    $expiredUrl = public_app_url('expired.html');
    $serviceWhatsapp = normalize_phone_number($db->getServiceContact()['whatsapp'] ?? '');
    if ($serviceWhatsapp !== '') $expiredUrl .= '?wa=' . rawurlencode($serviceWhatsapp);
    header('Location: ' . $expiredUrl, true, 303);
    exit;
}
$verifiedAccount=trim((string)($state['row']['subject']??''));
if ($verifiedAccount!=='') $db->synchronizeVerifiedAdmin($verifiedAccount);
if (!$db->usernameExists((string)($state['row']['subject']??''))) {
    if ($requestPath!=='/setup') { header('Location: '.$setupUrl,true,303); exit; }
    $flashes=pull_flashes();
    $presetUser=mb_strtolower(trim((string)($state['row']['subject']??'')));
    require project_path('view','setup.php'); exit;
}
if ($requestPath==='/activate' || $requestPath==='/setup') {
    header('Location: '.public_page_url(is_logged_in()?'dashboard':'login'),true,303); exit;
}
if (is_logged_in()) {
    $sessionUser=$db->getUser(current_user_id());
    if ($sessionUser===null || (string)$sessionUser['role']!==current_role()
        || (string)$sessionUser['username']!==current_username()) logout_user();
}
