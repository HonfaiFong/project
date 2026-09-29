# MediaService

## 1. Responsibility
- Presigned upload URL generation for S3-compatible object storage.
- MIME type and file size validation.
- Media object key generation and upload completion tracking.
- Direct-to-storage architecture (PHP never handles large file streams).

## 2. Owned Data
- S3-compatible buckets.

## 3. gRPC API
- `media.v1.MediaService/CreateUploadSession`
- `media.v1.MediaService/CompleteUpload`
