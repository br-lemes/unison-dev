#!/usr/bin/env php
<?php
declare(strict_types=1);

$home = getenv('HOME');
$password = '';

function askPassword(): void
{
    global $password;
    if ($password !== '') {
        return;
    }
    $command =
        "zenity --password --title='Authentication' --text='Enter your password'";
    $result = shell_exec($command);
    if ($result === null || trim($result) === '') {
        error('No password provided. Operation cancelled.');
        exit(1);
    }
    $password = trim($result);
}

function sudoExec(string $command, string $input = ''): string|false
{
    global $password;
    askPassword();
    $fullCommand = "sudo -S $command";
    $descSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($fullCommand, $descSpec, $pipes);
    if (!is_resource($process)) {
        return false;
    }
    fwrite($pipes[0], "$password\n$input");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return $output;
}

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
    $column = '--column=ID --column=Model --column=Serial';
    $title = '--title=Devices';
    $text = '--text="Select a device"';
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
    $lsblk = shell_exec('lsblk -Jo LABEL,MODEL,MOUNTPOINTS,NAME,PATH,SERIAL');
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
                    if (in_array('/', $child['mountpoints'])) {
                        continue;
                    }
                    if ($child['label'] === 'NIXROOT') {
                        $profile['mountpoints'] = $child['mountpoints'];
                        $profile['path'] = $child['path'];
                        $result[] = $profile;
                    }
                }
                continue;
            }
            if ($device['label'] !== 'NIXROOT') {
                continue;
            }
            if (in_array('/', $device['mountpoints'])) {
                continue;
            }
            $profile['mountpoints'] = $device['mountpoints'];
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

function isMounted(string $path): bool
{
    $result = shell_exec(
        'mountpoint -q ' . escapeshellarg($path) . ' && echo 1 || echo 0',
    );
    return trim($result) === '1';
}

function manageBindMounts(bool $mount): void
{
    global $home;
    $pairs = [
        [
            '/etc/NetworkManager/system-connections/',
            "$home/src/system-connections/",
        ],
        [
            '/mnt/etc/NetworkManager/system-connections/',
            "/mnt$home/src/system-connections/",
        ],
    ];

    foreach ($pairs as [$src, $dst]) {
        if (!is_dir($src) || !is_dir($dst)) {
            continue;
        }

        if ($mount && !isMounted($dst)) {
            sudoExec(
                "mount --bind -o 'X-mount.idmap=b:0:1000:1' " .
                    escapeshellarg($src) .
                    ' ' .
                    escapeshellarg($dst),
            );
        }
        if (!$mount && isMounted($dst)) {
            sudoExec('umount ' . escapeshellarg($dst));
        }
    }
}

function fscryptUnlock(): void
{
    global $home, $password;
    $targetDir = "/mnt$home";
    if (!is_dir($targetDir)) {
        return;
    }
    $targetDir = escapeshellarg($targetDir);
    exec("fscrypt status $targetDir 2>&1", $output, $statusCode);
    if ($statusCode !== 0) {
        return;
    }
    if (in_array('Unlocked: Yes', $output)) {
        return;
    }

    askPassword();
    $command = "fscrypt unlock $targetDir";
    $descSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descSpec, $pipes);
    if (!is_resource($process)) {
        error("Failed to unlock $targetDir.");
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

function mountDevice(string $path, array $mountpoints): void
{
    if (in_array('/mnt', $mountpoints)) {
        return;
    }
    if (isMounted('/mnt')) {
        error('/mnt is already in use by another device. Unmount it first.');
        exit(1);
    }
    $path = escapeshellarg($path);
    $result = sudoExec("mount $path /mnt");
    if ($result === false) {
        error('Failed to mount device at /mnt.');
        exit(1);
    }
}

function unison(array $device): void
{
    global $home;
    $profilePath = "{$device['model']}/{$device['serial']}";
    notify($profilePath);

    mountDevice($device['path'], $device['mountpoints']);

    fscryptUnlock();
    manageBindMounts(true);

    $unison = escapeshellarg("$home/.config/unison/nixos/$profilePath");
    $command = "UNISON=$unison unison-gui " . getAdditionalArgs();
    shell_exec($command);

    $title = "--title='Synchronization Complete'";
    $text = "--text='Do you want to unmount and safely eject the device?'";
    exec("zenity --question $title $text", $output, $statusCode);

    if ($statusCode !== 0) {
        return;
    }

    manageBindMounts(false);
    $path = escapeshellarg($device['path']);
    sudoExec("umount --all-targets $path");
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
