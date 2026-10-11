# RoadRunner gRPC transport

The six service YAML files describe the worker and protobuf registrations. The
Compose command starts `scripts/roadrunner-entrypoint.sh`, which applies the
official RoadRunner `grpc.tls` overrides after validating the deployment mode:

- `APP_ENV=local` with `GRPC_TLS_MODE=plaintext` keeps the local stack usable.
- `APP_ENV=production` requires `GRPC_TLS_MODE=tls` or `mtls`; missing or
  unreadable PEM files abort before RoadRunner starts.
- `tls` requires a server certificate/key and CA. `mtls` additionally requires
  `GRPC_TLS_CLIENT_AUTH_TYPE=require_and_verify_client_cert`, so RoadRunner—not
  the PHP worker—verifies the client certificate chain.

The application `x-service-token` remains required for caller authorization.
For deployments that terminate TLS in a sidecar or ingress, keep the backend
listener on a private network and configure that proxy to require and verify
client certificates before forwarding to the service network.
