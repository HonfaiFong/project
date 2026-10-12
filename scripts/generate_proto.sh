#!/bin/sh
set -eu

# 权威重生成：先清空旧产物，被删除的 proto 不会残留过期生成文件。
rm -rf contracts/gen/php contracts/gen/go
mkdir -p contracts/gen/php contracts/gen/go

# 输入顺序固定（LC_ALL=C 排序），同一固定工具链下重复生成结果应字节一致。
find contracts/proto -name '*.proto' -print | LC_ALL=C sort | xargs protoc \
    -I contracts/proto \
    --php_out=contracts/gen/php \
    --php-grpc_out=contracts/gen/php \
    --go_out=contracts/gen/go --go_opt=paths=source_relative \
    --go-grpc_out=contracts/gen/go --go-grpc_opt=paths=source_relative
