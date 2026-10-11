# RustFS local deployment

Run `make storage-up` after setting `RUSTFS_ACCESS_KEY` and `RUSTFS_SECRET_KEY` in `.env`. The server uses the RustFS 1.0.0 GA image and four persistent volumes. This is a single-node local deployment, not multi-node high availability. The one-shot permissions container gives RustFS's UID 10001 write access to the volumes. `storage-init` creates the configured bucket with administrator credentials if it does not exist.

Compose publishes both RustFS ports on `127.0.0.1` by default, including the web console on `9001`. Set `RUSTFS_BIND_ADDRESS` explicitly only when a controlled external listener is required; for production, keep it at `127.0.0.1` or remove the host port publication entirely and put the console behind an authenticated, restricted ingress. The container itself continues to listen on its internal network address so application containers can use `rustfs:9000`.

The current MediaService is a skeleton. Before it is enabled, provision a separate application access key with object-level access to the bucket. `STORAGE_ACCESS_KEY` and `STORAGE_SECRET_KEY` in `.env.example` are placeholders; this Compose stack does not create an IAM user. Keep the RustFS administrator credentials out of MediaService.

`STORAGE_ENDPOINT` is for internal server requests. Presigned browser uploads need `STORAGE_PUBLIC_ENDPOINT` (a host reachable by the client), because the signed Host must match the upload request. In deployed environments use HTTPS and configure bucket CORS for the exact web origin and required `PUT`, `GET`, and `HEAD` methods.
