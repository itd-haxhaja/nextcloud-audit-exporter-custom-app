#!/bin/sh
#
# Copies the bundled audit_export Nextcloud app into a Nextcloud apps directory
# (default: /custom_apps). Entrypoint of the init-container image built from the
# Dockerfile in this repository; safe to run on every container start: the app
# directory is swapped in with rename(), so a half-copied app is never visible to
# Nextcloud (dot-prefixed temp directories are ignored by Nextcloud's app scan).
#
# Environment:
#   TARGET_DIR  directory that holds Nextcloud's custom apps (mount the custom_apps
#               volume there), default /custom_apps
#
set -eu

APP_ID="audit_export"
SRC="/audit_export"
TARGET_DIR="${TARGET_DIR:-/custom_apps}"

version="$(awk -F'[<>]' '/<version>/{print $3; exit}' "$SRC/appinfo/info.xml")"

if [ ! -d "$TARGET_DIR" ]; then
    echo "install-app: target directory $TARGET_DIR does not exist (mount the Nextcloud custom_apps volume there)" >&2
    exit 1
fi
if [ ! -w "$TARGET_DIR" ]; then
    echo "install-app: target directory $TARGET_DIR is not writable by uid $(id -u)" >&2
    exit 1
fi

tmp="$TARGET_DIR/.$APP_ID.tmp"
old="$TARGET_DIR/.$APP_ID.old"

rm -rf "$tmp" "$old"
cp -R "$SRC" "$tmp"
if [ -d "$TARGET_DIR/$APP_ID" ]; then
    mv "$TARGET_DIR/$APP_ID" "$old"
fi
mv "$tmp" "$TARGET_DIR/$APP_ID"
rm -rf "$old"

echo "install-app: $APP_ID $version installed into $TARGET_DIR/$APP_ID"
