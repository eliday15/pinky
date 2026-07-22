#!/usr/bin/env bash
#
# Construye la imagen base de Pinky y la guarda en el registry local del server.
# El Dockerfile de la app hace FROM de ese tag.
#
#   ./docker/build-base.sh php8.4-2
#
# El build corre EN el server (por ssh, con Dockerfile.base por stdin), no en tu
# Mac: el server ya es x86_64 —así que no hay drama de --platform— y la imagen
# queda del lado donde se usa, sin subir ~500MB por tu conexión. Dockerfile.base
# no hace COPY del contexto, por eso se puede mandar por stdin sin clonar el repo
# en el server.
#
# Después de publicar, actualizá el ARG BASE_IMAGE en el Dockerfile con el tag
# nuevo y commiteá los dos archivos juntos.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# El server 'Pinky' de Coolify. docker requiere sudo (NOPASSWD está activo).
SSH_HOST="${PINKY_SSH_HOST:-administrator@155.117.40.143}"
REGISTRY="${PINKY_REGISTRY:-localhost:5000}"
IMAGE="pinky-base"
VERSION="${1:-}"

# Auth ssh. Se prefiere una llave; si no hay, y está seteado PINKY_SSH_PASSWORD,
# se usa sshpass. La contraseña NUNCA se hardcodea acá.
SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
SSH_CMD=(ssh)
if [ -n "${PINKY_SSH_KEY:-}" ]; then
    SSH_OPTS+=(-o IdentitiesOnly=yes -i "$PINKY_SSH_KEY")
elif [ -n "${PINKY_SSH_PASSWORD:-}" ]; then
    if ! command -v sshpass >/dev/null 2>&1; then
        echo "error: PINKY_SSH_PASSWORD seteado pero no está 'sshpass' (brew install sshpass)." >&2
        exit 1
    fi
    export SSHPASS="$PINKY_SSH_PASSWORD"
    SSH_CMD=(sshpass -e ssh)
    SSH_OPTS+=(-o PreferredAuthentications=password)
fi

remote() { "${SSH_CMD[@]}" "${SSH_OPTS[@]}" "$SSH_HOST" "$@"; }

if [ -z "$VERSION" ]; then
    echo "uso: $0 <version>    (ej: $0 php8.4-2)" >&2
    echo >&2
    echo "Tag en uso ahora, según el Dockerfile:" >&2
    grep -m1 'ARG BASE_IMAGE=' Dockerfile >&2 || true
    echo >&2
    echo "Si el ssh no entra solo, exportá una de estas antes de correr:" >&2
    echo "   PINKY_SSH_KEY=/ruta/a/la/llave    (preferido)" >&2
    echo "   PINKY_SSH_PASSWORD='...'          (usa sshpass)" >&2
    exit 1
fi

REF="${REGISTRY}/${IMAGE}:${VERSION}"

echo "==> Verificando el registry en ${SSH_HOST}"
if ! remote "curl -sf http://127.0.0.1:5000/v2/ >/dev/null"; then
    echo "error: el registry no responde en 127.0.0.1:5000." >&2
    echo "Se crea una sola vez con:" >&2
    echo "   sudo docker run -d --name registry --restart always \\" >&2
    echo "     -p 127.0.0.1:5000:5000 -v registry_data:/var/lib/registry registry:2" >&2
    exit 1
fi

# Pisar un tag ya publicado deja imágenes distintas bajo el mismo nombre según
# cuándo cada quien hizo pull. Mejor subir de versión.
if remote "curl -s http://127.0.0.1:5000/v2/${IMAGE}/tags/list" | grep -q "\"${VERSION}\""; then
    echo "error: ${REF} ya existe en el registry. Subí la versión en vez de pisarlo." >&2
    exit 1
fi

echo "==> Construyendo ${REF} en el server (compila extensiones + freetds, ~3-5 min)"
remote "sudo DOCKER_BUILDKIT=1 docker build -t '${REF}' -" < Dockerfile.base

echo "==> Publicando en el registry local"
remote "sudo docker push '${REF}'"

echo "==> Verificando que se pueda bajar"
remote "sudo docker rmi '${REF}' >/dev/null 2>&1 || true; sudo docker pull '${REF}' >/dev/null && echo OK"

echo
echo "==> Listo: ${REF}"
echo "Siguiente paso — apuntá el deploy al tag nuevo en el Dockerfile:"
echo "    ARG BASE_IMAGE=${REF}"
