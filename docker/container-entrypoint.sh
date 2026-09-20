#!/bin/sh
set -eu

# The production image runs as www-data. Runtime storage must therefore be a
# writable volume owned by uid/gid 33; the image itself remains read-only.
if [ ! -d /var/lib/meteonexa ] || [ ! -w /var/lib/meteonexa ]; then
  printf '%s\n' 'MeteoNexa: /var/lib/meteonexa is not writable by www-data (uid 33).' >&2
  exit 78
fi

umask 007
exec docker-php-entrypoint "$@"
