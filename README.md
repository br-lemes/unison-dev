# Unison Dev

A tool to automate file synchronization with devices using Unison, featuring an
intuitive graphical interface.

## Overview

Unison Dev is a PHP script that streamlines file synchronization between your
system and external storage devices. It automatically detects connected devices,
mounts them as needed, unlocks encrypted partitions, and runs pre-configured
Unison synchronization profiles.

## Features

- **Automatic hardware detection** based on model and serial number
- **Graphical interface** using Zenity for device selection and password entry
- **Automatic mounting** of unmounted devices via `udisksctl`
- **fscrypt support** to unlock encrypted directories on the fly
- **ID-mapped bind mounts** for system files (e.g., NetworkManager) using
  `pkexec`
- **Safe ejection** with optional unmount and power-off after synchronization
- **System notifications** throughout the process via `notify-send`

## Requirements

### System Dependencies

- PHP 8.2+
- `lsblk` (part of util-linux)
- `zenity` (GUI dialogs)
- `udisksctl` (disk management)
- `fscrypt` (for encrypted directory support)
- `pkexec` (privilege escalation for bind mounts)
- `unison-gui` (Unison GUI)
- `notify-send` (notifications)

### Installing Dependencies

#### Ubuntu/Debian

```bash
sudo apt install php-cli zenity udisks2 fscrypt pkexec unison-gtk libnotify-bin
```

#### Fedora

```bash
sudo dnf install php-cli zenity udisks2 fscrypt polkit unison-gtk libnotify
```

#### Arch Linux

```bash
sudo pacman -S php zenity udisks2 fscrypt polkit unison libnotify
```

### Compatibility Note

On some distributions, the Unison GUI executable may be named `unison-gtk`. If
so, create a symbolic link for compatibility:

```bash
sudo ln -s /usr/bin/unison-gtk /usr/bin/unison-gui
```

### Development Dependencies

```bash
npm install
# or
bun install
```

## Profile Structure

The script expects Unison profiles to be organized by hardware in
`~/.config/unison/nixos/`. The partition on the device must be labeled
**NIXROOT**.

```text
~/.config/unison/nixos/
└── [MODEL]/
    └── [SERIAL]/
        └── Unison configuration files (.prf, etc.)
```

### Example Structure

```text
~/.config/unison/nixos/
└── SanDisk_Ultra/
    └── 4C530001234567890123/
        ├── default.prf
        └── common.unison
```

## Special Configurations

### Bind Mounts (idmap)

The script automatically manages bind mounts for sensitive system connections:
`sudo mount --bind -o "X-mount.idmap=b:0:1000:1" ...`

This allows the user (UID 1000) to sync files normally owned by root (like
NetworkManager profiles) across the system and the external device.

## Usage

### Basic Execution

```bash
./unison-dev.php
```

### With Additional Unison Arguments

```bash
./unison-dev.php -batch -silent
```

### How It Works

1. **Scanning**: Scans hardware using `lsblk` and matches against
   `~/.config/unison/nixos/`.
2. **Filtering**: Identifies partitions with the `NIXROOT` label.
3. **Mounting**: Mounts the device and captures the mount point dynamically via
   regex.
4. **Decryption**: Detects and unlocks **fscrypt** directories if present.
5. **Privilege Escalation**: Sets up **bind mounts** for system directories
   using `pkexec`.
6. **Sync**: Launches `unison-gui` with the corresponding hardware profile.
7. **Cleanup**: Prompts for safe ejection; if confirmed, it unmounts bind mounts
   and powers off the device.

## Troubleshooting

### Device not recognized

1. Check if the device appears in the system: `lsblk`.
2. Ensure the partition label is exactly `NIXROOT`.
3. Verify the folder structure matches:
   `~/.config/unison/nixos/[MODEL]/[SERIAL]/`.

### Permission errors

1. Ensure your user is in the `wheel` or `sudo` group for `pkexec` operations.
2. Check if `fscrypt` is correctly initialized for the target directory.

### GUI not appearing

1. Check if `zenity` is installed.
2. Verify the `DISPLAY` environment variable is correctly set.

## Contributing

1. Fork the project.
2. Create a feature branch (`git checkout -b feature/new-feature`).
3. Commit your changes (`git commit -am 'Add new feature'`).
4. Push to the branch (`git push origin feature/new-feature`).
5. Open a Pull Request.

## License

This project is distributed under the BSD Zero Clause License. See the `LICENSE`
file for details.
