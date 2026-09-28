#!/bin/sh
set -eu

mkdir -p contracts/gen/php contracts/gen/go

find contracts/proto -name '*.proto' -exec protoc \
    -I contracts/proto \
    --php_out=contracts/gen/php \
    --php-grpc_out=contracts/gen/php \
    --go_out=contracts/gen/go --go_opt=paths=source_relative \
    --go-grpc_out=contracts/gen/go --go-grpc_opt=paths=source_relative \
    {} +
