<?php

declare(strict_types=1);
final class PageController
{
    private const PAGES=[
        'dashboard'=>['Dashboard'],
        'settings'=>['Pengaturan'],
        'settings-admin'=>['Pengaturan'],
        'view-olt'=>['View OLT'],
        'edit-api-olt'=>['Edit Koneksi'],
        'add-device'=>['Tambah Perangkat'],
        'edit-device'=>['Edit Perangkat'],
        'view-mikrotik'=>['Detail MikroTik'],
        'view-mikrotik-interface'=>['Trafik MikroTik'],
        'view-switch'=>['Detail Switch'],
        'onus'=>['Daftar ONU'],
        'add-onu'=>['Register ONU'],
        'view-onu'=>['view ONU'],
        'notification'=>['Notifikasi'],
        'telegram'=>['Telegram'],
        'whatsapp'=>['WhatsApp'],
        'applogs'=>['App Log'],
    ];
    public static function resolve(string $requested): array
    {
        $page=$requested!==''?$requested:'dashboard';
        if(!isset(self::PAGES[$page]))$page='dashboard';
        return ['name'=>$page,'title'=>self::PAGES[$page][0],'lead'=>self::PAGES[$page][1] ?? ''];
    }
    public static function view(string $page): string
    {
        $views = [
            'onus' => 'onu-list.php',
            'view-olt' => 'olt.php',
            'view-onu' => 'onu.php',
            'view-mikrotik' => 'device/mikrotik.php',
            'view-mikrotik-interface' => 'device/interface.php',
            'view-switch' => 'device/switch.php',
        ];
        $relative = $views[$page] ?? ($page . '.php');
        return LINNMS_VIEW_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
