# Init-container image for the audit_export Nextcloud app.
#
# The image contains nothing but the app folder and a tiny entrypoint that copies it
# into a Nextcloud custom_apps directory (TARGET_DIR, default /custom_apps). Nextcloud
# itself cannot install an app from an image or tarball, so the app is delivered by
# running this image as an init container of the Nextcloud pod with the custom_apps
# volume mounted at /custom_apps; a Nextcloud entrypoint hook then runs
# `occ app:enable audit_export` / `occ upgrade`. See README.md ("Kubernetes").
#
# Build (Jenkinsfile): podman build --build-arg APP_VERSION=<info.xml version> .
FROM docker.io/library/alpine:3.21

ARG APP_VERSION=unknown
LABEL org.opencontainers.image.title="audit_export - AudIT Permission Exporter (Nextcloud app)" \
      org.opencontainers.image.description="Nextcloud app audit_export packaged as an init-container that copies the app into custom_apps" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.vendor="ITdesign" \
      org.opencontainers.image.source="https://bitbucket.org/itdesign-at/audit.nextcloud-export-app"

# The app files: whole build context minus .dockerignore (keep that file in sync with the
# Makefile `build` target). uid/gid 33 = www-data of the official nextcloud image.
COPY --chown=33:33 . /audit_export/
COPY scripts/install-app.sh /usr/local/bin/install-app
RUN chmod 0755 /usr/local/bin/install-app \
    && rm -rf /audit_export/scripts \
    && chmod -R u=rwX,go=rX /audit_export

USER 33:33
ENV TARGET_DIR=/custom_apps
ENTRYPOINT ["/usr/local/bin/install-app"]
