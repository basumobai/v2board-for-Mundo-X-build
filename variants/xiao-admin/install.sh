#!/usr/bin/env bash
set -euo pipefail

package_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
action=${1:-}
site_arg=${2:-}
if [[ ! "$action" =~ ^(install|restore)$ || -z "$site_arg" || ! -d "$site_arg" ]]; then
    echo 'Usage: bash install.sh install /path/to/xiao-v2board' >&2
    echo '       bash install.sh restore /path/to/xiao-v2board /path/to/backup' >&2
    exit 2
fi
site_root=$(cd -- "$site_arg" && pwd -P)
assets="$site_root/public/assets/admin"
view="$site_root/resources/views/admin.blade.php"
route="$site_root/app/Http/Routes/V1/AdminRoute.php"
[[ -f "$site_root/artisan" && -f "$route" && -d "$assets" && -f "$view" ]] || {
    echo 'Target must be an existing Xiao V2Board installation.' >&2; exit 1;
}
[[ ! -L "$assets" && ! -L "$view" ]] || {
    echo 'Resolve the admin assets/view symlinks before installation.' >&2; exit 1;
}

if [[ "$action" == install ]]; then
    (cd -- "$package_dir" && sha256sum --check SHA256SUMS >/dev/null)
    expected_route=$(sed -n 's/.*"adminRouteSha256": "\([0-9a-f]*\)".*/\1/p' "$package_dir/compatibility.json")
    actual_route=$(sha256sum -- "$route")
    [[ -n "$expected_route" && "${actual_route%% *}" == "$expected_route" ]] || {
        echo 'This backend has a different API contract. Use the Xiao revision documented in compatibility.json or review its routes before installing.' >&2
        exit 1
    }
    # Backups include the Blade view and stay outside the site's public directory.
    backup_dir=$(umask 077; mktemp -d -- "$(dirname -- "$site_root")/$(basename -- "$site_root")-xiao-admin-backup-XXXXXXXX")
    cp -a -- "$assets" "$backup_dir/admin"
    cp -a -- "$view" "$backup_dir/admin.blade.php"
    printf '%s\n' "$site_root" > "$backup_dir/site-root.txt"
    chmod 600 "$backup_dir/site-root.txt"
    stage_dir=$(mktemp -d -- "$site_root/public/assets/.xiao-admin-XXXXXXXX")
    trap 'rm -rf -- "$stage_dir"' EXIT
    cp -R -- "$package_dir/public/assets/admin" "$stage_dir/admin"
    find "$stage_dir/admin" -type d -exec chmod 755 {} +
    find "$stage_dir/admin" -type f -exec chmod 644 {} +
    mv -- "$assets" "$stage_dir/previous"
    if ! mv -- "$stage_dir/admin" "$assets"; then
        mv -- "$stage_dir/previous" "$assets"
        exit 1
    fi
    if ! install -m 644 -- "$package_dir/resources/views/admin.blade.php" "$view"; then
        mv -- "$assets" "$stage_dir/failed"
        mv -- "$stage_dir/previous" "$assets"
        cp -a -- "$backup_dir/admin.blade.php" "$view"
        exit 1
    fi
    echo "Installed Xiao admin. Backup: $backup_dir"
    printf 'Rollback: bash %q restore %q %q\n' "$package_dir/install.sh" "$site_root" "$backup_dir"
else
    backup_arg=${3:-}
    [[ -d "$backup_arg/admin" && -f "$backup_arg/admin.blade.php" && -f "$backup_arg/site-root.txt" ]] || {
        echo 'A complete admin backup is required.' >&2; exit 1;
    }
    [[ "$(cat -- "$backup_arg/site-root.txt")" == "$site_root" ]] || {
        echo 'The backup belongs to another site.' >&2; exit 1;
    }
    stage_dir=$(mktemp -d -- "$site_root/public/assets/.xiao-admin-XXXXXXXX")
    trap 'rm -rf -- "$stage_dir"' EXIT
    cp -a -- "$backup_arg/admin" "$stage_dir/admin"
    mv -- "$assets" "$stage_dir/previous"
    if ! mv -- "$stage_dir/admin" "$assets"; then
        mv -- "$stage_dir/previous" "$assets"; exit 1;
    fi
    if ! cp -a -- "$backup_arg/admin.blade.php" "$view"; then
        mv -- "$assets" "$stage_dir/failed"
        mv -- "$stage_dir/previous" "$assets"; exit 1;
    fi
    echo 'Restored the original admin assets and view.'
fi

# Xiao may run inside Docker or with a versioned PHP binary. Allow an explicit
# executable and print the remaining command when the runtime is unavailable.
php_bin=${PHP_BIN:-php}
if command -v "$php_bin" >/dev/null 2>&1 && [[ -f "$site_root/vendor/autoload.php" ]]; then
    (cd -- "$site_root" && "$php_bin" artisan view:clear)
else
    printf 'Clear compiled views in the site PHP runtime: cd %q && php artisan view:clear\n' "$site_root"
fi
