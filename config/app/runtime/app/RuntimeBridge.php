<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config_default.php';

final class RemoteFault extends RuntimeException
{
    public function __construct(public readonly string $remoteCode, string $message, public readonly int $remoteStatus = 0, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}

final class RuntimeBridge
{
    private string $localKey;
    private string $stateDir;
    private ?array $stateCache = null;

    public function __construct(private PDO $pdo, private array $config)
    {
        if (!extension_loaded('sodium')) throw new RuntimeException('Komponen keamanan tidak tersedia.');
        $stateDir = rtrim((string)($config['store'] ?? '/config/app/data/core'), '/\\');
        if (!is_dir($stateDir) || !is_writable($stateDir)) throw new RuntimeException('State lokal tidak siap.');
        $this->stateDir = $stateDir;
        $keyPath = $stateDir . DIRECTORY_SEPARATOR . 'node.bin';
        if (is_file($keyPath)) $key = file_get_contents($keyPath);
        else {
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            if (file_put_contents($keyPath, $key, LOCK_EX) === false) throw new RuntimeException('State lokal tidak dapat dibuat.');
            @chmod($keyPath, 0600);
        }
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('State lokal tidak valid.');
        $this->localKey = $key;
    }

    public function nodeId(): string
    {
        $row = $this->row();
        $id = trim((string)($row['node_ref'] ?? ''));
        if ($id !== '') return $id;
        $id = 'ins_' . bin2hex(random_bytes(16));
        $stmt=$this->pdo->prepare("INSERT INTO runtime_state(id,node_ref,mode) VALUES(1,:node_ref,'pending') ON DUPLICATE KEY UPDATE node_ref=IF(node_ref='',VALUES(node_ref),node_ref)");
        $stmt->execute(['node_ref'=>$id]);
        $row=$this->row();
        return trim((string)($row['node_ref']??$id));
    }

    public function state(): array
    {
        if (is_array($this->stateCache)) return $this->stateCache;
        $row=$this->row();
        if (!$row) return $this->stateCache = ['mode'=>'pending','node'=>$this->nodeId()];
        $rowStatus=(string)($row['mode']??'');
        if (in_array($rowStatus, ['pending'], true)) {
            return $this->stateCache = [
                'mode'=>'pending',
                'node'=>$this->nodeId(),
                'row'=>$row,
                'message'=>'Aktivasi diperlukan.',
            ];
        }
        $packet=$this->decodePacket((string)($row['state_blob']??''));
        if (!$packet) return $this->stateCache = ['mode'=>'pending','node'=>$this->nodeId()];
        try {
            $claims=$this->verifyPacket($packet);
            $expires=strtotime((string)($claims['exp']??''));
            if ($expires===false || $expires <= time()) return $this->stateCache = ['mode'=>'ended','claims'=>$claims,'row'=>$row];
            $this->syncNotificationAvailability($claims);
            return $this->stateCache = ['mode'=>'ready','claims'=>$claims,'row'=>$row];
        } catch (Throwable $e) {
            return $this->stateCache = ['mode'=>'fault','message'=>$e->getMessage(),'row'=>$row];
        }
    }

    public function cleanupDeadline(): ?int
    {
        $state = $this->state();
        if (($state['mode'] ?? '') !== 'ended') return null;
        $expiresAt = strtotime((string)($state['claims']['exp'] ?? ''));
        if ($expiresAt === false) return null;

        $deadlinePath = data_path('core', 'expired-delete-at');
        ensure_data_directory(dirname($deadlinePath));
        $lock = @fopen($deadlinePath . '.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Jadwal penghapusan tidak dapat dikunci.');
        }

        try {
            $contactPath = data_path('core', 'expired-whatsapp');
            $contactStatement = $this->pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='service_whatsapp' LIMIT 1");
            $contactNumber = normalize_phone_number($contactStatement instanceof PDOStatement ? $contactStatement->fetchColumn() : '');
            if ($contactNumber === '') {
                @unlink($contactPath);
            } elseif (!is_file($contactPath) || trim((string)@file_get_contents($contactPath)) !== $contactNumber) {
                if (@file_put_contents($contactPath, $contactNumber . PHP_EOL, LOCK_EX) !== false) @chmod($contactPath, 0644);
            }

            $existing = is_file($deadlinePath) ? (int)trim((string)@file_get_contents($deadlinePath)) : 0;

            $deleteAt = max($expiresAt, time());
            if ($existing > 0 && $existing <= $deleteAt) return $existing;
            $temporaryPath = $deadlinePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($temporaryPath, (string)$deleteAt . PHP_EOL, LOCK_EX) === false
                || !@rename($temporaryPath, $deadlinePath)
            ) {
                @unlink($temporaryPath);
                throw new RuntimeException('Jadwal penghapusan tidak dapat disimpan.');
            }
            @chmod($deadlinePath, 0644);
            return $deleteAt;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function open(string $username, string $token, LinnmsRepository $db): array
    {
        $username=mb_strtolower(trim($username)); $token=trim($token);
        if (!preg_match('/^[a-z0-9._-]{3,80}$/',$username)) throw new InvalidArgumentException('Username tidak valid.');
        if ($token==='') throw new InvalidArgumentException('Key wajib diisi.');
        $packet=$this->exchange($username,$token,$this->nodeId(),'channel/open');
        $claims=$this->verifyPacket($packet);
        $this->pdo->beginTransaction();
        try {
            $this->saveState($username,$token,$packet,$claims);
            $db->synchronizeVerifiedAdmin($username);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        $this->stateCache = null;
        return $claims;
    }

    public function refresh(bool $force=false): bool
    {
        $this->stateCache = null;
        $row=$this->row();
        if (!$row || trim((string)($row['state_blob']??''))==='') return false;
        if (!$force) {
            $next=$this->nextRefreshAt($row);
            if ($next!==null && $next>time()) return false;
        }
        $username=trim((string)($row['subject']??''));
        $token=$this->decrypt((string)($row['secret_blob']??''));
        if ($username==='' || $token===null) return false;
        $retryMinutes = $this->refreshIntervalMinutes();
        try {
            $packet=$this->exchange($username,$token,(string)$row['node_ref'],'channel/sync');
            $claims=$this->verifyPacket($packet);
            $this->saveState($username,$token,$packet,$claims);
            return true;
        } catch (RemoteFault $e) {
            if (in_array($e->remoteCode, ['access_denied'], true)) {
                $this->pdo->prepare("UPDATE runtime_state SET mode='pending',note=:note,due_at=NULL WHERE id=1")
                    ->execute(['note'=>'Aktivasi diperlukan.']);
                return false;
            }
            $this->pdo->prepare("UPDATE runtime_state SET note=:note,due_at=DATE_ADD(NOW(), INTERVAL {$retryMinutes} MINUTE) WHERE id=1")
                ->execute(['note'=>mb_substr($e->getMessage(),0,500)]);
            return false;
        } catch (Throwable $e) {
            $this->pdo->prepare("UPDATE runtime_state SET note=:note,due_at=DATE_ADD(NOW(), INTERVAL {$retryMinutes} MINUTE) WHERE id=1")
                ->execute(['note'=>mb_substr($e->getMessage(),0,500)]);
            return false;
        }
    }

    public function refreshIfDueSilently(): void { $this->refresh(false); }

    public function refreshScheduleSignalPath(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'refresh.sock';
    }

    public function secondsUntilRefresh(): ?int
    {
        $row = $this->row();
        if (!$row || (string)($row['mode'] ?? '') !== 'ready') return null;
        if (trim((string)($row['state_blob'] ?? '')) === '') return null;
        $next = $this->nextRefreshAt($row);
        if ($next === null) return 0;
        return max(0, $next - time());
    }

    public function allowsPublicRegistration(): bool
    {
        $state = $this->state();
        return ($state['mode'] ?? '') === 'ready'
            && !empty($state['claims']['flags']['f2'])
            && $this->publicRegistrationQuotaUnlimited();
    }

    private function publicRegistrationQuotaUnlimited(): bool
    {
        $quota = $this->claims()['quota'] ?? null;
        if (!is_array($quota)) return false;
        foreach (['q0', 'q1', 'q2', 'q3'] as $key) {
            $value = $quota[$key] ?? null;
            if (!is_numeric($value) || (int)$value !== 0) return false;
        }
        return true;
    }

    public function allowsTelegram(): bool
    {
        $state = $this->state();
        return ($state['mode'] ?? '') === 'ready'
            && !empty($state['claims']['flags']['f1']);
    }

    public function allowsWhatsApp(): bool
    {
        $state = $this->state();
        return ($state['mode'] ?? '') === 'ready'
            && !empty($state['claims']['flags']['f0']);
    }

    private function syncNotificationAvailability(array $claims): void
    {
        $telegram = !empty($claims['flags']['f1']) ? '1' : '0';
        $whatsapp = !empty($claims['flags']['f0']) ? '1' : '0';
        $rows = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings
             WHERE setting_key IN ('telegram_bot_enabled','whatsapp_notification_service_enabled')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($rows['telegram_bot_enabled'] ?? null) === $telegram
            && ($rows['whatsapp_notification_service_enabled'] ?? null) === $whatsapp) return;

        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings(setting_key,setting_value)
             VALUES ('telegram_bot_enabled',:telegram),('whatsapp_notification_service_enabled',:whatsapp)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute(['telegram' => $telegram, 'whatsapp' => $whatsapp]);
    }

    
    public function devicePermissions(): array
    {
        $claims = $this->claims();
        $devices = $claims['devices'] ?? null;
        if (!is_array($devices)) return [];
        $permissions = [];
        foreach ($devices as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') continue;
            $permissions[$key] = is_bool($value)
                ? $value
                : in_array((string)$value, ['1', 'true', 'yes'], true);
        }
        return $permissions;
    }

    
    
    public function externalApiConnection(): array
    {
        $claims = $this->claims();
        $api = $claims['api'] ?? null;
        if (!is_array($api)) {
            return ['enabled' => false, 'base' => '', 'key' => '', 'managed' => false];
        }
        $enabled = is_bool($api['enabled'] ?? null)
            ? (bool)$api['enabled']
            : in_array((string)($api['enabled'] ?? ''), ['1', 'true', 'yes'], true);
        return [
            'enabled' => $enabled,
            'base' => trim((string)($api['base'] ?? '')),
            'key' => trim((string)($api['key'] ?? '')),
            'managed' => true,
        ];
    }

    
    private function claims(): array
    {
        $state = $this->state();
        if (($state['mode'] ?? '') !== 'ready') return [];
        $claims = $state['claims'] ?? null;
        return is_array($claims) ? $claims : [];
    }

    public function userCount(): int { return (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); }

    public function createProfile(LinnmsRepository $db, array $input): int
    {
        $state=$this->state();
        if (($state['mode']??'')!=='ready') throw new RuntimeException('Akses belum aktif.');

        $username=mb_strtolower(trim((string)($state['row']['subject']??'')));
        $password=(string)($input['password']??'');
        $confirm=(string)($input['password_confirm']??'');
        $display=trim((string)($input['display_name']??''));

        if (!preg_match('/^[a-z0-9._-]{3,80}$/',$username)) {
            throw new RuntimeException('Data aktivasi tidak valid. Aktivasi ulang diperlukan.');
        }
        if ($db->usernameExists($username)) throw new RuntimeException('Akun terverifikasi sudah tersedia.');
        if ($display==='') throw new InvalidArgumentException('Nama pengguna wajib diisi.');
        if (mb_strlen($display)>120) throw new InvalidArgumentException('Nama pengguna terlalu panjang.');
        if (strlen($password)<8) throw new InvalidArgumentException('Password minimal 8 karakter.');
        if ($password!==$confirm) throw new InvalidArgumentException('Konfirmasi password tidak sama.');

        return $db->createUser($username,$password,$display,0,0,0,'admin');
    }

    public function updateContext(): array
    {
        $row = $this->row();
        if (!$row || (string)($row['mode'] ?? '') !== 'ready') {
            throw new RuntimeException('Akses aktif diperlukan untuk pembaruan.');
        }

        $username = trim((string)($row['subject'] ?? ''));
        $token = $this->decrypt((string)($row['secret_blob'] ?? ''));
        $nodeId = trim((string)($row['node_ref'] ?? ''));
        $baseUrl = $this->endpoint();

        if ($username === '' || $token === null || $nodeId === '' || $baseUrl === '') {
            throw new RuntimeException('Credential pembaruan tidak siap.');
        }

        return [
            'url' => $baseUrl . 'channel/blob',
            'account' => $username,
            'token' => $token,
            'node' => $nodeId,
        ];
    }

    public function registrationOtpRequest(string $phone): array
    {
        return $this->registrationOtpExchange('channel/otp/request', ['phone' => $phone]);
    }

    public function registrationOtpVerify(string $phone, int $challenge, string $code): array
    {
        return $this->registrationOtpExchange('channel/otp/verify', ['phone' => $phone, 'challenge' => $challenge, 'code' => $code]);
    }

    private function registrationOtpExchange(string $path, array $data): array
    {
        if (!$this->allowsPublicRegistration()) throw new RuntimeException('Pendaftaran tidak tersedia.');
        $row = $this->row();
        if (!is_array($row)) throw new RuntimeException('Aktivasi belum tersedia.');
        $username = trim((string)($row['subject'] ?? ''));
        $token = $this->decrypt((string)($row['secret_blob'] ?? ''));
        $node = trim((string)($row['node_ref'] ?? ''));
        if ($username === '' || $token === null || $node === '') throw new RuntimeException('Aktivasi belum tersedia.');
        return $this->exchange($username, $token, $node, $path, $data);
    }

    private function row(): ?array
    {
        $row=$this->pdo->query('SELECT * FROM runtime_state WHERE id=1 LIMIT 1')->fetch();
        return is_array($row)?$row:null;
    }

    private function exchange(string $username,string $token,string $nodeId,string $path,array $data=[]): array
    {
        $base=$this->endpoint();
        if($base==='') {
            throw new RuntimeException('Endpoint API belum dikonfigurasi pada appliance.');
        }

        $body=json_encode(
            ['account'=>$username,'token'=>$token,'node'=>$nodeId] + $data,
            JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
        );
        $url=$base.ltrim($path,'/');

        $ch=curl_init($url);
        if($ch===false) {
            throw new RuntimeException('Endpoint API belum dikonfigurasi pada appliance.');
        }

        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>(int)($this->config['timeout']??15),
        ]);

        $raw=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if(!is_string($raw)) {
            throw new RuntimeException('Layanan sedang tidak dapat dihubungi. Periksa koneksi internet dan DNS server.');
        }

        $decoded=json_decode($raw,true);
        if(!is_array($decoded)) {
            $message='invalid response (HTTP '.$http.').';
            $this->trace('[invalid-response] '.$message.' URL='.$url.' BODY='.mb_substr($raw,0,500));
            throw new RuntimeException($message);
        }

        if(empty($decoded['ok']) || !is_array($decoded['data']??null)) {
            $source=trim((string)($decoded['source']??''));
            $code=trim((string)($decoded['code']??''));
            $message=trim((string)($decoded['error']??''));
            $requestId=trim((string)($decoded['request_id']??''));

            if($message==='') {
                $message='Permintaan ditolak (HTTP '.$http.').';
            } elseif($source==='service' && !str_starts_with($message,'Layanan:')) {
                $message='Layanan: '.$message;
            }

            if($requestId!=='') $message.=' [ref '.$requestId.']';

            $this->trace(
                '[remote-response]'
                .' HTTP='.$http
                .' code='.($code!==''?$code:'unknown')
                .' request_id='.($requestId!==''?$requestId:'-')
            );

            if($code!=='') {
                throw new RemoteFault($code,$message,$http,max(0,(int)($decoded['retry_after_seconds']??0)));
            }
            throw new RuntimeException($message);
        }

        return $decoded['data'];
    }

    private function trace(string $message): void
    {
        $line=date('c').' '.$message.PHP_EOL;
        $dir=dirname(shared_log_path('service.log'));
        if(!is_dir($dir)) @mkdir($dir,0770,true);
        if(@file_put_contents($dir.'/service.log',$line,FILE_APPEND|LOCK_EX)===false) {
            error_log('[runtime] '.$message);
        }
    }

    private function endpoint(): string
    {
        $row=$this->row();
        $stored=$this->normalizeEndpoint((string)($row['relay']??''), false);
        if($stored!=='') return $stored;
        return $this->normalizeEndpoint((string)($this->config['relay']??''), false);
    }

    private function normalizeEndpoint(string $url, bool $strict=true): string
    {
        $url=trim($url);
        if($url==='') {
            if($strict) throw new RuntimeException('Endpoint kosong.');
            return '';
        }
        if(!filter_var($url,FILTER_VALIDATE_URL)) {
            if($strict) throw new RuntimeException('Endpoint tidak valid.');
            return '';
        }
        $parts=parse_url($url);
        if(!is_array($parts)) {
            if($strict) throw new RuntimeException('Endpoint tidak valid.');
            return '';
        }
        $scheme=strtolower((string)($parts['scheme']??''));
        if(!in_array($scheme,['http','https'],true)||!empty($parts['user'])||!empty($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])) {
            if($strict) throw new RuntimeException('Endpoint tidak valid.');
            return '';
        }
        $path='/' . trim((string)($parts['path']??''), '/') . '/';
        if(!str_ends_with($path,'/api/v1/')) {
            if($strict) throw new RuntimeException('Endpoint tidak valid.');
            return '';
        }
        return rtrim($url,'/').'/';
    }

    private function verifyPacket(array $packet): array
    {
        $claims=$packet['body']??null; $signature=base64_decode((string)($packet['sig']??''),true);
        if(!is_array($claims)||!is_string($signature)) throw new RuntimeException('Data verifikasi tidak valid.');
        $publicB64=trim((string)($this->config['verify']??''));
        if($publicB64===''||$publicB64==='REPLACE_WITH_LINNMS_PUBLIC_KEY') throw new RuntimeException('Public key belum di konfigurasi');
        $public=base64_decode($publicB64,true); if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('Public verification key tidak valid.');
        if(!sodium_crypto_sign_verify_detached($signature,CanonicalJson::encode($claims),$public)) throw new RuntimeException('Verifikasi gagal.');
        if((string)($claims['node']??'')!==$this->nodeId()) throw new RuntimeException('Verifikasi gagal: ID instalasi tidak cocok');
        if((string)($claims['kind']??'')!=='core') throw new RuntimeException('Data tidak valid');
        $endpointFromState=trim((string)($claims['relay']['base']??''));
        if($endpointFromState!=='') $this->normalizeEndpoint($endpointFromState, true);
        $notBefore=strtotime((string)($claims['nbf']??'')); if($notBefore!==false && $notBefore>time()+300) throw new RuntimeException('Akses tidak berlaku.');
        return $claims;
    }

    private function refreshIntervalMinutes(): int
    {
        return LinnmsDefaults::Q_REFRESH_MINUTES;
    }

    private function nextRefreshAt(array $row): ?int
    {
        // Recalculate from the last attempt so edits to config_default.php take effect after reload.
        $lastAttempt = strtotime((string)($row['updated_at'] ?? ''));
        if ($lastAttempt !== false) return $lastAttempt + $this->refreshIntervalMinutes() * 60;
        $due = strtotime((string)($row['due_at'] ?? ''));
        return $due === false ? null : $due;
    }

    private function signalRefreshSchedule(): void
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            'unix://' . $this->refreshScheduleSignalPath(),
            $errno,
            $error,
            0.2,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) return;
        @fwrite($socket, "reload\n");
        @fclose($socket);
    }

    private function saveState(string $username,string $token,array $packet,array $claims): void
    {
        $json=json_encode($packet,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $minutes=$this->refreshIntervalMinutes();
        $endpointFromState=$this->normalizeEndpoint((string)($claims['relay']['base']??''), false);
        if($endpointFromState==='') $endpointFromState=$this->endpoint();
        $stmt=$this->pdo->prepare("INSERT INTO runtime_state(id,subject,secret_blob,node_ref,relay,state_blob,rev,valid_until,mode,opened_at,touched_at,due_at,note) VALUES(1,:subject,:key,:node,:relay,:state,:rev,:until,'ready',NOW(),NOW(),DATE_ADD(NOW(),INTERVAL {$minutes} MINUTE),NULL) ON DUPLICATE KEY UPDATE subject=VALUES(subject),secret_blob=VALUES(secret_blob),node_ref=VALUES(node_ref),relay=VALUES(relay),state_blob=VALUES(state_blob),rev=VALUES(rev),valid_until=VALUES(valid_until),mode='ready',opened_at=COALESCE(opened_at,NOW()),touched_at=NOW(),due_at=DATE_ADD(NOW(),INTERVAL {$minutes} MINUTE),note=NULL");
        $stmt->execute(['subject'=>$username,'key'=>$this->encrypt($token),'node'=>$this->nodeId(),'relay'=>$endpointFromState,'state'=>$json,'rev'=>(int)($claims['rev']??0),'until'=>(new DateTimeImmutable((string)$claims['exp']))->format('Y-m-d H:i:s')]);
        $this->syncBrandingSettings($claims);
        $this->signalRefreshSchedule();
    }

    private function syncBrandingSettings(array $claims): void
    {
        $label = trim((string)($claims['ui']['label'] ?? ''));
        if ($label === '' && isset($claims['branding']['app_name'])) $label = trim((string)$claims['branding']['app_name']);
        if ($label !== '') { $this->putAppSetting('app_name', mb_substr($label, 0, 255)); $this->clearAppIdentityCache(); }

        $version = trim((string)($claims['branding']['app_version'] ?? ''));
        if ($version !== '') { $this->putAppSetting('app_version', mb_substr($version, 0, 40)); $this->clearAppIdentityCache(); }

        $contact = $claims['branding']['contact'] ?? ($claims['contact'] ?? null);
        if (!is_array($contact)) return;
        $map = ['phone' => 'service_phone', 'email' => 'service_email', 'whatsapp' => 'service_whatsapp', 'github' => 'service_github', 'web' => 'service_web'];
        foreach ($map as $source => $key) {
            $value = trim((string)($contact[$source] ?? ''));
            if ($value !== '') $this->putAppSetting($key, mb_substr($value, 0, 255));
        }
    }

    private function putAppSetting(string $key, string $value): void
    {
        try {
            $this->pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute(['key' => $key, 'value' => $value]);
        } catch (Throwable $error) { error_log('app_settings ' . $key . ': ' . $error->getMessage()); }
    }

    private function clearAppIdentityCache(): void
    {
        $file = cache_path('app_identity.json');
        if (is_file($file)) @unlink($file);
    }

    private function encrypt(string $plain): string { $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->localKey)); }
    private function decrypt(string $encoded): ?string { $raw=base64_decode($encoded,true); if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return null; $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$this->localKey); return is_string($plain)?$plain:null; }
    private function decodePacket(string $json): ?array { if(trim($json)==='')return null; $v=json_decode($json,true); return is_array($v)?$v:null; }
}
