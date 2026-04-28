#!/usr/bin/env php
<?php
declare(strict_types=1);

$home = getenv('HOME');

function arrayToList(array $devices): string
{
    $result = '';
    foreach ($devices as $index => $device) {
        $result .= "$index ";
        foreach (['model', 'serial'] as $key) {
            $result .= escapeshellarg($device[$key]) . ' ';
        }
        $result .= ' ';
    }
    return $result;
}

function chooseDevice(array $devices): array
{
    $list = arrayToList($devices);
    $column = '--column=ID --column=Modelo --column=Serial';
    $title = '--title=Dispositivos';
    $text = '--text="Selecione um dispositivo"';
    $command = "zenity --list $title $text $column $list";
    $output = shell_exec($command);
    if (!$output || !is_numeric($output) || !isset($devices[(int) $output])) {
        error('Operation cancelled.');
        exit(1);
    }
    return $devices[(int) $output];
}

function error(string $message): void
{
    $message = escapeshellarg($message);
    shell_exec("zenity --error --text=$message");
}

function getAdditionalArgs(): string
{
    global $argv;
    $args = array_slice($argv, 1);
    $args = array_map('escapeshellarg', $args);
    return implode(' ', $args);
}

function getDevices(): array
{
    $lsblk = shell_exec('lsblk -Jo LABEL,MODEL,MOUNTPOINT,NAME,PATH,SERIAL');
    if (!$lsblk) {
        return [];
    }
    return json_decode($lsblk, true)['blockdevices'];
}

function getMatchingDevices(): array
{
    $profiles = getProfiles();
    $devices = getDevices();
    $result = [];
    foreach ($profiles as $profile) {
        foreach ($devices as $device) {
            if ($device['model'] !== $profile['model']) {
                continue;
            }
            if ($device['serial'] !== $profile['serial']) {
                continue;
            }
            if (isset($device['children'])) {
                foreach ($device['children'] as $child) {
                    if ($child['mountpoint'] === '/') {
                        continue;
                    }
                    if ($child['label'] === 'NIXROOT') {
                        $profile['mountpoint'] = $child['mountpoint'];
                        $profile['path'] = $child['path'];
                        $result[] = $profile;
                    }
                }
                continue;
            }
            if ($device['label'] !== 'NIXROOT') {
                continue;
            }
            if ($device['mountpoint'] === '/') {
                continue;
            }
            $profile['mountpoint'] = $device['mountpoint'];
            $profile['path'] = $device['path'];
            $result[] = $profile;
        }
    }
    return $result;
}

function getProfiles(): array
{
    global $home;
    $path = "$home/.config/unison/nixos";
    $profiles = [];
    foreach (glob("$path/*/*", GLOB_ONLYDIR) as $dir) {
        $parts = explode('/', $dir);
        $count = count($parts);
        if ($count < 2) {
            continue;
        }
        $profiles[] = [
            'model' => $parts[$count - 2],
            'serial' => $parts[$count - 1],
        ];
    }
    return $profiles;
}

function info(string $message): void
{
    $message = escapeshellarg($message);
    shell_exec("zenity --info --text=$message");
}

function notify(string $message): void
{
    $message = escapeshellarg($message);
    shell_exec("notify-send $message");
}

function manageBindMounts(string $mountpoint, bool $mount): void
{
    global $home;
    $pairs = [
        [
            '/etc/NetworkManager/system-connections/',
            "$home/src/system-connections/",
        ],
        [
            rtrim($mountpoint, '/') . '/etc/NetworkManager/system-connections/',
            rtrim($mountpoint, '/') . "$home/src/system-connections/",
        ],
    ];

    foreach ($pairs as [$src, $dst]) {
        if (!is_dir($src) || !is_dir($dst)) {
            continue;
        }

        $isMounted = shell_exec(
            'mountpoint -q ' . escapeshellarg($dst) . ' && echo 1 || echo 0',
        );
        if ($mount && trim($isMounted) === '0') {
            shell_exec(
                "pkexec mount --bind -o 'X-mount.idmap=b:0:1000:1' " .
                    escapeshellarg($src) .
                    ' ' .
                    escapeshellarg($dst),
            );
        }
        if (!$mount && trim($isMounted) === '1') {
            shell_exec('pkexec umount ' . escapeshellarg($dst));
        }
    }
}

function fscryptUnlock(string $mountpoint)
{
    global $home;
    $targetDir = rtrim($mountpoint, '/') . $home;
    if (!is_dir($targetDir)) {
        return;
    }
    $targetDir = escapeshellarg($targetDir);
    exec("fscrypt status $targetDir 2>&1", $output, $statusCode);
    if ($statusCode !== 0) {
        return;
    }

    $notUnlocked = "O diretório $targetDir não foi desbloqueado.";

    $title = "--title='Desbloqueio fscrypt'";
    $message = "--text='Insira sua senha para desbloquear $targetDir'";
    $command = "zenity --password $title $message";
    $password = shell_exec($command);
    if ($password === null) {
        error($notUnlocked);
        return;
    }
    $password = trim($password);
    if ($password === '') {
        error($notUnlocked);
        return;
    }

    $command = "fscrypt unlock $targetDir";
    $descSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descSpec, $pipes);
    if (!is_resource($process)) {
        error($notUnlocked);
        return;
    }
    fwrite($pipes[0], $password . "\n");
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);
}

function unison(array $device): void
{
    global $home;
    $profilePath = "{$device['model']}/{$device['serial']}";
    notify($profilePath);
    if ($device['mountpoint'] === null) {
        $mount = shell_exec("udisksctl mount -b {$device['path']}");
        if ($mount === false || $mount === null) {
            error('Failed to mount device.');
            exit(1);
        }
        if (preg_match('/at\s+(.+)$/i', trim($mount), $matches)) {
            $device['mountpoint'] = $matches[1];
        }
    }
    $command = '';
    fscryptUnlock($device['mountpoint']);
    manageBindMounts($device['mountpoint'], true);
    $unison = escapeshellarg("$home/.config/unison/nixos/$profilePath");
    $command = "UNISON=$unison unison-gui " . getAdditionalArgs();
    shell_exec($command);

    $title = "--title='Sincronização Finalizada'";
    $text = "--text='Deseja desmontar e ejetar o dispositivo com segurança?'";
    exec("zenity --question $title $text", $output, $statusCode);

    if ($statusCode !== 0) {
        return;
    }

    manageBindMounts($device['mountpoint'], false);
    shell_exec("udisksctl unmount -b {$device['path']}");
    shell_exec("udisksctl power-off -b {$device['path']}");
    info('Device safely ejected. You can now remove it.');
}

$devices = getMatchingDevices();

if (empty($devices)) {
    error('No devices found.');
    exit(1);
}

if (count($devices) === 1) {
    $device = $devices[0];
} else {
    $device = chooseDevice($devices);
}

unison($device);
