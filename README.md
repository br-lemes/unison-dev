# Unison Dev

A tool to automate file synchronization with devices using Unison, featuring an
intuitive graphical interface.

## Overview

Unison Dev is a PHP script that streamlines file synchronization between your
system and external storage devices. It automatically detects connected devices,
mounts them as needed, and runs pre-configured Unison synchronization profiles.

## Features

- **Automatic detection** of devices based on model, serial number, and label
- **Graphical interface** using Zenity for device selection
- **Automatic mounting** of unmounted devices
- **Support for both root and user** Unison profiles
- **Safe ejection** after synchronization
- **System notifications** throughout the process

## Requirements

### System Dependencies

- PHP 8.2+
- `lsblk` (part of util-linux)
- `zenity` (GUI dialogs)
- `udisksctl` (disk management)
- `pkexec` (privilege escalation)
- `unison-gui` (Unison GUI)
- `notify-send` (notifications)

### Installing Dependencies

#### Ubuntu/Debian

```bash
sudo apt install php-cli zenity udisks2 pkexec unison-gtk libnotify-bin
```

#### Fedora

```bash
sudo dnf install php-cli zenity udisks2 polkit unison-gtk libnotify
```

#### Arch Linux

```bash
sudo pacman -S php zenity udisks2 polkit unison libnotify
```

### PolicyKit Configuration (Required for root profiles)

To allow the script to run Unison with administrative privileges, you must
install the PolicyKit policy file:

```bash
sudo cp unison.policy /usr/share/polkit-1/actions/
```

This file allows `pkexec` to execute `unison-gui` as root when needed. Without
it, root profiles will not work.

On some distributions the Unison GUI executable may be named `unison-gtk`. If
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

The script expects Unison profiles to be organized as follows:

```text
~/.config/unison/
├── root/
│   └── [MODEL]/
│       └── [SERIAL]/
│           └── [LABEL]/
│               └── Unison configuration files
└── user/
    └── [MODEL]/
        └── [SERIAL]/
            └── [LABEL]/
                └── Unison configuration files
```

### Example Structure

```text
~/.config/unison/
├── root/
│   └── SanDisk_Ultra/
│       └── 4C530001234567890123/
│           └── BACKUP/
│               ├── default.prf
│               └── ...
└── user/
    └── Kingston_DataTraveler/
        └── E0D55EA315E6/
            └── DOCS/
                ├── default.prf
                └── ...
```

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

1. The script scans connected devices using `lsblk`
2. Matches them against configured profiles in `~/.config/unison/`
3. If multiple devices are found, displays a selection interface
4. Mounts the device if necessary
5. Launches Unison GUI with the corresponding profile
6. After synchronization, safely unmounts and ejects the device

## Profile Configuration

### User Profiles (`user`)

Run with normal user permissions.

### Root Profiles (`root`)

Run with administrative privileges using `pkexec`. Useful for:

- Synchronizing system files
- Accessing directories that require root privileges
- Backing up system configurations

## Troubleshooting

### Device not recognized

1. Check if the device appears in the system: `lsblk`
2. Verify the folder structure in `~/.config/unison/`
3. Ensure the device's model, serial, and label match the profile path exactly

### Permission errors (root profiles)

1. Make sure the `unison.policy` file is installed:

   ```bash
   sudo cp unison.policy /usr/share/polkit-1/actions/
   ```

2. Verify `pkexec` is installed and working
3. Check if the user is in the appropriate group (usually `wheel` or `sudo`)
4. Test manually: `pkexec unison-gui`

### GUI not appearing

1. Check if `zenity` is installed
2. Verify the `DISPLAY` variable is set
3. Test by running `zenity --info --text="Test"` manually

## Contributing

1. Fork the project
2. Create a feature branch (`git checkout -b feature/new-feature`)
3. Commit your changes (`git commit -am 'Add new feature'`)
4. Push to the branch (`git push origin feature/new-feature`)
5. Open a Pull Request

## License

This project is distributed under the BSD Zero Clause License. See the `LICENSE`
file for details.
