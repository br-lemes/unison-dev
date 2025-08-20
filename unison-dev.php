#!/usr/bin/env php
<?php
declare(strict_types=1);

$home = getenv('HOME');

function arrayToList(array $devices): string
{
    $result = '';
    foreach ($devices as $index => $device) {
        $result .= "$index ";
        foreach (['model', 'serial', 'label'] as $key) {
            $result .= escapeshellarg($device[$key]) . ' ';
        }
        $result .= ' ';
    }
    return $result;
}

function chooseDevice(array $devices): array
{
    $list = arrayToList($devices);
    $column = '--column=ID --column=Modelo --column=Serial --column=Label';
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
    $profiles = array_merge(getProfiles(true), getProfiles(false));
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
                    if ($child['label'] === $profile['label']) {
                        $profile['mountpoint'] = $child['mountpoint'];
                        $profile['path'] = $child['path'];
                        $result[] = $profile;
                    }
                }
                continue;
            }
            if ($device['label'] !== $profile['label']) {
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

function getProfiles(bool $root): array
{
    global $home;
    $path = "$home/.config/unison/" . ($root ? 'root' : 'user');
    $profiles = [];
    foreach (glob("$path/*/*/*", GLOB_ONLYDIR) as $dir) {
        $parts = explode('/', $dir);
        $count = count($parts);
        if ($count < 3) {
            continue;
        }
        $profiles[] = [
            'label' => $parts[$count - 1],
            'model' => $parts[$count - 3],
            'root' => $root,
            'serial' => $parts[$count - 2],
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

function unison(array $device): void
{
    global $home;
    $profilePath = "{$device['model']}/{$device['serial']}/{$device['label']}";
    notify($profilePath);
    if ($device['mountpoint'] === null) {
        $mount = shell_exec("udisksctl mount -b {$device['path']}");
        if ($mount === false || $mount === null) {
            error('Failed to mount device.');
            exit(1);
        }
    }
    $command = '';
    if ($device['root']) {
        $display = escapeshellarg(getenv('DISPLAY'));
        $sshAuthSock = escapeshellarg(getenv('SSH_AUTH_SOCK'));
        $unison = escapeshellarg("$home/.config/unison/root/$profilePath");
        $xAuthority = escapeshellarg(getenv('XAUTHORITY'));
        $command .= "pkexec env DISPLAY=$display ";
        $command .= "SSH_AUTH_SOCK=$sshAuthSock XAUTHORITY=$xAuthority ";
    } else {
        $unison = escapeshellarg("$home/.config/unison/user/$profilePath");
    }
    $command .= "UNISON=$unison unison-gui " . getAdditionalArgs();
    shell_exec($command);
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
