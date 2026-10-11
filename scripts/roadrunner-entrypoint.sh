#!/bin/sh

# Start one of the service-specific RoadRunner gRPC configurations.  The
# checked-in YAML files are intentionally transport-neutral for local use;
# secure deployments inject the TLS section through RoadRunner's supported
# --override flag after this gate has validated every referenced PEM file.
set -eu

if [ "$#" -ne 1 ]; then
    echo "usage: roadrunner-entrypoint.sh /app/infra/roadrunner/<service>.yaml" >&2
    exit 64
fi

config=$1
app_env=$(printf '%s' "${APP_ENV:-local}" | tr '[:upper:]' '[:lower:]')
mode=$(printf '%s' "${GRPC_TLS_MODE:-}" | tr '[:upper:]' '[:lower:]')

case "$app_env" in
    production|prod)
        if [ -z "$mode" ]; then
            echo "GRPC_TLS_MODE is required when APP_ENV=$app_env" >&2
            exit 78
        fi
        ;;
    *)
        mode=${mode:-plaintext}
        ;;
esac

case "$mode" in
    plaintext)
        case "$app_env" in
            production|prod)
                echo "plaintext gRPC is forbidden when APP_ENV=$app_env" >&2
                exit 78
                ;;
        esac
        exec rr serve -c "$config"
        ;;
    tls|mtls)
        : "${GRPC_TLS_CA_FILE:?GRPC_TLS_CA_FILE is required for secure gRPC transport}"
        : "${GRPC_TLS_SERVER_CERT_FILE:?GRPC_TLS_SERVER_CERT_FILE is required for secure gRPC transport}"
        : "${GRPC_TLS_SERVER_KEY_FILE:?GRPC_TLS_SERVER_KEY_FILE is required for secure gRPC transport}"
        ;;
    *)
        echo "GRPC_TLS_MODE must be plaintext, tls, or mtls" >&2
        exit 78
        ;;
esac

for pem in "$GRPC_TLS_CA_FILE" "$GRPC_TLS_SERVER_CERT_FILE" "$GRPC_TLS_SERVER_KEY_FILE"; do
    if [ ! -f "$pem" ] || [ ! -r "$pem" ]; then
        echo "gRPC TLS PEM path is missing or unreadable: $pem" >&2
        exit 78
    fi
done

client_auth=${GRPC_TLS_CLIENT_AUTH_TYPE:-no_client_certs}
case "$mode" in
    tls)
        if [ "$client_auth" != "no_client_certs" ]; then
            echo "GRPC_TLS_CLIENT_AUTH_TYPE must be no_client_certs when GRPC_TLS_MODE=tls" >&2
            exit 78
        fi
        ;;
    mtls)
        if [ "$client_auth" != "require_and_verify_client_cert" ]; then
            echo "mTLS requires GRPC_TLS_CLIENT_AUTH_TYPE=require_and_verify_client_cert" >&2
            exit 78
        fi
        ;;
esac

exec rr serve -c "$config" \
    -o "grpc.tls.key=$GRPC_TLS_SERVER_KEY_FILE" \
    -o "grpc.tls.cert=$GRPC_TLS_SERVER_CERT_FILE" \
    -o "grpc.tls.root_ca=$GRPC_TLS_CA_FILE" \
    -o "grpc.tls.client_auth_type=$client_auth"
