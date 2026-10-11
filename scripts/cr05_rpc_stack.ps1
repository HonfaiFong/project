[CmdletBinding()]
param(
[ValidateSet('all', 'migrate', 'fixture', 'start', 'stop', 'status', 'cleanup')]
    [string]$Action = 'all',
    [string]$Prefix = $(if ($env:RPC_PREFIX) { $env:RPC_PREFIX } else { 'social_test_cr05_repro_' }),
    [string]$MySqlName = 'codex-identity-review-mysql',
    [string]$MySqlPassword = $(if ($env:CR05_MYSQL_PASSWORD) { $env:CR05_MYSQL_PASSWORD } else { 'codex-identity-test-20261011' }),
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
$UseRtk = $null -ne (Get-Command rtk -ErrorAction SilentlyContinue)

if ($Prefix -notmatch '^social_test_[A-Za-z0-9_]+$' -or $Prefix.Length -gt 50) {
    throw 'Prefix must match social_test_[A-Za-z0-9_]+ and be at most 50 characters.'
}
if ($Prefix -match 'mitm') {
    throw 'The isolated RPC stack prefix may not refer to mitm data.'
}
if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot 'phinx.php'))) {
    throw "Project root does not contain phinx.php: $ProjectRoot"
}

$Image = 'project-php-runtime:dev'
$MySqlImage = 'mysql:8.4'
$AnonymousKey = 'anon-key-cr05-20261011-000000000000000000'
$Tokens = @{
    GatewayUser = 'gw-user-cr05-20261011-000000000000'
    GatewayContent = 'gw-content-cr05-20261011-000000000000'
    GatewaySocial = 'gw-social-cr05-20261011-000000000000'
    GatewayChat = 'gw-chat-cr05-20261011-000000000000'
    GatewayMessage = 'gw-message-cr05-20261011-000000000000'
    AuthUser = 'auth-user-cr05-20261011-000000000000'
    ContentUser = 'content-user-cr05-20261011-000000000000'
    SocialUser = 'social-user-cr05-20261011-000000000000'
    ChatUser = 'chat-user-cr05-20261011-000000000000'
    ContentSocial = 'content-social-cr05-20261011-000000000000'
    SocialContent = 'social-content-cr05-20261011-000000000000'
    ChatContent = 'chat-content-cr05-20261011-000000000000'
    MessageContent = 'message-content-cr05-20261011-000000000000'
    ModerationContent = 'moderation-content-cr05-20261011-000000000000'
    ChatSocial = 'chat-social-cr05-20261011-000000000000'
    MessageChat = 'message-chat-cr05-20261011-000000000000'
}

$WorkerNames = @('cr05-rpc-user', 'cr05-rpc-content', 'cr05-rpc-social', 'cr05-rpc-chat', 'cr05-rpc-message')
$Databases = @('auth_db', 'user_db', 'content_db', 'social_db', 'chat_db', 'message_db', 'meta')

function Invoke-RtkDocker {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$DockerArgs)

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    # RTK emits its no-hook advisory on stderr even for successful commands;
    # suppress that advisory here while retaining the native exit status.
    if ($UseRtk) {
        $output = @(& rtk docker @DockerArgs 2>$null)
    } else {
        $output = @(& docker @DockerArgs 2>$null)
    }
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousPreference
    $output | Out-Host
    if ($exitCode -ne 0) {
        $operation = if ($DockerArgs.Count -gt 0) { $DockerArgs[0] } else { 'unknown' }
        throw "isolated docker operation '$operation' failed with exit code $exitCode"
    }
}

function Test-Container {
    param([string]$Name)

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    if ($UseRtk) {
        & rtk docker container inspect $Name 2>$null | Out-Null
    } else {
        & docker container inspect $Name 2>$null | Out-Null
    }
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousPreference
    return $exitCode -eq 0
}

function Get-ContainerField {
    param(
        [string]$Name,
        [string]$Format
    )

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    if ($UseRtk) {
        $result = @(& rtk docker inspect $Name --format $Format 2>$null)
    } else {
        $result = @(& docker inspect $Name --format $Format 2>$null)
    }
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousPreference
    if ($exitCode -ne 0) {
        return $null
    }

    return (($result -join '').Trim())
}

function Get-ContainerLabels {
    param([string]$Name)

    $json = Get-ContainerField $Name '{{json .Config.Labels}}'
    if ([string]::IsNullOrWhiteSpace($json)) {
        return $null
    }

    try {
        return ($json | ConvertFrom-Json)
    } catch {
        return $null
    }
}

function Assert-MySqlOwnership {
    if (-not (Test-Container $MySqlName)) {
        throw "Required isolated MySQL container is not available: $MySqlName"
    }

    $image = Get-ContainerField $MySqlName '{{.Config.Image}}'
    $labels = Get-ContainerLabels $MySqlName
    if (($null -eq $labels) -or ($image -ne $MySqlImage) -or
        ($labels.'com.social.identity.review' -ne 'cr05-rpc-stack') -or
        ($labels.'com.social.identity.review.prefix' -ne $Prefix) -or
        ($labels.'com.social.identity.review.role' -ne 'mysql') -or
        ($labels.'com.social.identity.review.mysql' -ne $MySqlName)) {
        throw "Refusing to use '$MySqlName': image/ownership/prefix labels do not identify this script's isolated MySQL container for prefix '$Prefix'."
    }
}

function Assert-MySql {
    Assert-MySqlOwnership
    $status = Get-ContainerField $MySqlName '{{.State.Status}}'
    if ($status -ne 'running') {
        throw "Required isolated MySQL container '$MySqlName' is not running."
    }
}

function Ensure-MySql {
    if (Test-Container $MySqlName) {
        Assert-MySqlOwnership

        $status = Get-ContainerField $MySqlName '{{.State.Status}}'
        if ($status -ne 'running') {
            Invoke-RtkDocker start $MySqlName
        }
    } else {
        Invoke-RtkDocker run --detach --name $MySqlName `
            --label 'com.social.identity.review=cr05-rpc-stack' `
            --label "com.social.identity.review.prefix=$Prefix" `
            --label 'com.social.identity.review.role=mysql' `
            --label "com.social.identity.review.mysql=$MySqlName" `
            --env "MYSQL_ROOT_PASSWORD=$MySqlPassword" $MySqlImage
    }

    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        $previousPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        if ($UseRtk) {
            & rtk docker exec $MySqlName mysqladmin --protocol=TCP -uroot "-p$MySqlPassword" ping 2>$null | Out-Null
        } else {
            & docker exec $MySqlName mysqladmin --protocol=TCP -uroot "-p$MySqlPassword" ping 2>$null | Out-Null
        }
        $exitCode = $LASTEXITCODE
        $ErrorActionPreference = $previousPreference
        if ($exitCode -eq 0) {
            return
        }
        Start-Sleep -Seconds 1
    }

    throw "Isolated MySQL container '$MySqlName' did not become ready within 60 seconds."
}

function Assert-RpcWorkerOwnership {
    param([string]$Name)

    $image = Get-ContainerField $Name '{{.Config.Image}}'
    $labels = Get-ContainerLabels $Name
    if (($null -eq $labels) -or ($image -ne $Image) -or
        ($labels.'com.social.identity.review' -ne 'cr05-rpc-stack') -or
        ($labels.'com.social.identity.review.prefix' -ne $Prefix) -or
        ($labels.'com.social.identity.review.role' -ne 'worker') -or
        ($labels.'com.social.identity.review.worker' -ne $Name)) {
        throw "Refusing to remove or reuse '$Name': image/ownership/prefix/role labels do not identify this script's worker for prefix '$Prefix'."
    }
}

function Remove-RpcWorkers {
    # Preflight every existing fixed-name worker before removing any of them;
    # a foreign container must never be partially cleaned up.
    $existing = @($WorkerNames | Where-Object { Test-Container $_ })
    foreach ($name in $existing) {
        Assert-RpcWorkerOwnership $name
    }
    foreach ($name in $existing) {
        Invoke-RtkDocker rm -f $name
    }
}

function Remove-TestDatabases {
    Assert-MySql
    $names = $Databases | ForEach-Object { $Prefix + $_ }
    $sql = ($names | ForEach-Object { 'DROP DATABASE IF EXISTS `' + ($_ -replace '`', '``') + '`;' }) -join ' '
    Invoke-RtkDocker exec $MySqlName mysql --protocol=TCP -uroot "-p$MySqlPassword" "--execute=$sql"
}

function Invoke-PhpRuntime {
    param(
        [Parameter(Position = 0, ValueFromRemainingArguments = $true)][string[]]$RuntimeArgs,
        [string[]]$ExtraEnvironment = @()
    )

    $mount = "$ProjectRoot`:/app"
    $extra = $ExtraEnvironment | ForEach-Object { '--env'; $_ }
    Invoke-RtkDocker run --rm --network "container:$MySqlName" --volume $mount --workdir /app `
        --env "MYSQL_HOST=127.0.0.1" --env 'MYSQL_PORT=3306' --env 'MYSQL_USER=root' `
        --env "MYSQL_PASSWORD=$MySqlPassword" --env "MYSQL_SCHEMA_PREFIX=$Prefix" @extra $Image @RuntimeArgs
}

function Invoke-Migrate {
    # Stop only the named test workers before replacing their prefixed
    # databases; this avoids an old process racing a DROP DATABASE on reruns.
    Remove-RpcWorkers
    Ensure-MySql
    Remove-TestDatabases
    Invoke-PhpRuntime -ExtraEnvironment @('MYSQL_MIGRATION_LOCK_TIMEOUT=30') php vendor/bin/phinx migrate --configuration=phinx.php
}

function Invoke-Fixture {
    Assert-MySql
    Invoke-PhpRuntime -ExtraEnvironment @('SERVICE_NAME=cr05-rpc-fixture') php scripts/cr05_rpc_fixture.php
}

function Start-RpcWorker {
    param(
        [string]$Name,
        [string]$Config,
        [string[]]$Environment
    )

    $mount = "$ProjectRoot`:/app"
    $dockerArgs = @(
        'run', '--detach', '--name', $Name, '--network', "container:$MySqlName",
        '--label', 'com.social.identity.review=cr05-rpc-stack',
        '--label', "com.social.identity.review.prefix=$Prefix",
        '--label', 'com.social.identity.review.role=worker',
        '--label', "com.social.identity.review.worker=$Name",
        '--volume', $mount, '--workdir', '/app'
    )
    foreach ($item in $Environment) {
        $dockerArgs += @('--env', $item)
    }
    $dockerArgs += @($Image, 'rr', 'serve', '-c', $Config)
    Invoke-RtkDocker @dockerArgs
}

function Start-RpcWorkers {
    Assert-MySql
    Remove-RpcWorkers

    Start-RpcWorker 'cr05-rpc-user' 'infra/roadrunner/user.yaml' @(
        'SERVICE_NAME=user-service', 'SERVICE_INSTANCE_ID=cr05-user', 'WORKER_ID=2',
        "MYSQL_HOST=127.0.0.1", 'MYSQL_PORT=3306', 'MYSQL_USER=root', "MYSQL_PASSWORD=$MySqlPassword", "USER_DB_NAME=${Prefix}user_db",
        "INTERNAL_RPC_GATEWAY_USER_TOKEN=$($Tokens.GatewayUser)", "INTERNAL_RPC_AUTH_USER_TOKEN=$($Tokens.AuthUser)",
        "INTERNAL_RPC_CONTENT_USER_TOKEN=$($Tokens.ContentUser)", "INTERNAL_RPC_SOCIAL_USER_TOKEN=$($Tokens.SocialUser)",
        "INTERNAL_RPC_CHAT_USER_TOKEN=$($Tokens.ChatUser)"
    )
    Start-RpcWorker 'cr05-rpc-content' 'infra/roadrunner/content.yaml' @(
        'SERVICE_NAME=content-service', 'SERVICE_INSTANCE_ID=cr05-content', 'WORKER_ID=3',
        "MYSQL_HOST=127.0.0.1", 'MYSQL_PORT=3306', 'MYSQL_USER=root', "MYSQL_PASSWORD=$MySqlPassword", "CONTENT_DB_NAME=${Prefix}content_db",
        "INTERNAL_RPC_GATEWAY_CONTENT_TOKEN=$($Tokens.GatewayContent)", "INTERNAL_RPC_CHAT_CONTENT_TOKEN=$($Tokens.ChatContent)",
        "INTERNAL_RPC_SOCIAL_CONTENT_TOKEN=$($Tokens.SocialContent)", "INTERNAL_RPC_MESSAGE_CONTENT_TOKEN=$($Tokens.MessageContent)",
        "INTERNAL_RPC_MODERATION_CONTENT_TOKEN=$($Tokens.ModerationContent)",
        'GRPC_USER_SERVICE=127.0.0.1:50052', "GRPC_USER_TOKEN=$($Tokens.ContentUser)",
        'GRPC_USER_TIMEOUT_MS=1000',
        'GRPC_SOCIAL_SERVICE=127.0.0.1:50054', "GRPC_SOCIAL_SERVICE_TOKEN=$($Tokens.ContentSocial)",
        'GRPC_SOCIAL_BLOCK_TIMEOUT_MS=1000',
        "ANONYMOUS_IDENTITY_KEY=$AnonymousKey"
    )
    Start-RpcWorker 'cr05-rpc-social' 'infra/roadrunner/social.yaml' @(
        'SERVICE_NAME=social-service', 'SERVICE_INSTANCE_ID=cr05-social', 'WORKER_ID=4',
        "MYSQL_HOST=127.0.0.1", 'MYSQL_PORT=3306', 'MYSQL_USER=root', "MYSQL_PASSWORD=$MySqlPassword", "SOCIAL_DB_NAME=${Prefix}social_db",
        "INTERNAL_RPC_GATEWAY_SOCIAL_TOKEN=$($Tokens.GatewaySocial)", "INTERNAL_RPC_CHAT_SOCIAL_TOKEN=$($Tokens.ChatSocial)",
        "INTERNAL_RPC_CONTENT_SOCIAL_TOKEN=$($Tokens.ContentSocial)",
        'GRPC_USER_SERVICE=127.0.0.1:50052', "GRPC_USER_SERVICE_TOKEN=$($Tokens.SocialUser)",
        'GRPC_USER_DIRECTORY_TIMEOUT_MS=1000',
        'GRPC_CONTENT_SERVICE=127.0.0.1:50053', "GRPC_CONTENT_SERVICE_TOKEN=$($Tokens.SocialContent)",
        'GRPC_CONTENT_AUTHORIZATION_TIMEOUT_MS=1000'
    )
    Start-RpcWorker 'cr05-rpc-chat' 'infra/roadrunner/chat.yaml' @(
        'SERVICE_NAME=chat-service', 'SERVICE_INSTANCE_ID=cr05-chat', 'WORKER_ID=5',
        "MYSQL_HOST=127.0.0.1", 'MYSQL_PORT=3306', 'MYSQL_USER=root', "MYSQL_PASSWORD=$MySqlPassword", "CHAT_DB_NAME=${Prefix}chat_db",
        "INTERNAL_RPC_GATEWAY_CHAT_TOKEN=$($Tokens.GatewayChat)", "INTERNAL_RPC_MESSAGE_CHAT_TOKEN=$($Tokens.MessageChat)",
        'GRPC_USER_SERVICE=127.0.0.1:50052', "GRPC_USER_SERVICE_TOKEN=$($Tokens.ChatUser)",
        'GRPC_USER_DIRECTORY_TIMEOUT_MS=1000',
        'GRPC_CONTENT_SERVICE=127.0.0.1:50053', "GRPC_CONTENT_SERVICE_TOKEN=$($Tokens.ChatContent)",
        'GRPC_CONTENT_AUTHORIZATION_TIMEOUT_MS=1000',
        'GRPC_SOCIAL_SERVICE=127.0.0.1:50054', "GRPC_SOCIAL_SERVICE_TOKEN=$($Tokens.ChatSocial)",
        'GRPC_SOCIAL_BLOCK_TIMEOUT_MS=1000',
        "ANONYMOUS_IDENTITY_KEY=$AnonymousKey"
    )
    Start-RpcWorker 'cr05-rpc-message' 'infra/roadrunner/message.yaml' @(
        'SERVICE_NAME=message-service', 'SERVICE_INSTANCE_ID=cr05-message', 'WORKER_ID=6',
        "MYSQL_HOST=127.0.0.1", 'MYSQL_PORT=3306', 'MYSQL_USER=root', "MYSQL_PASSWORD=$MySqlPassword", "MESSAGE_DB_NAME=${Prefix}message_db",
        "INTERNAL_RPC_GATEWAY_MESSAGE_TOKEN=$($Tokens.GatewayMessage)",
        'GRPC_CHAT_SERVICE=127.0.0.1:50055', "GRPC_CHAT_SERVICE_TOKEN=$($Tokens.MessageChat)",
        'GRPC_CHAT_MEMBER_TIMEOUT_MS=2000',
        "ANONYMOUS_IDENTITY_KEY=$AnonymousKey"
    )
}

function Show-Status {
    Assert-MySql
    Invoke-RtkDocker ps --format '{{.Names}} {{.Status}} {{.Ports}}'
    foreach ($name in $WorkerNames) {
        if (-not (Test-Container $name)) {
            Write-Output "$name absent"
        }
    }
}

switch ($Action) {
    'migrate' { Invoke-Migrate }
    'fixture' { Invoke-Fixture }
    'start' { Start-RpcWorkers }
    'stop' { Remove-RpcWorkers }
    'cleanup' {
        if (Test-Container $MySqlName) {
            Assert-MySqlOwnership
        }
        Remove-RpcWorkers
        if (Test-Container $MySqlName) {
            Invoke-RtkDocker rm -f $MySqlName
        }
    }
    'status' { Show-Status }
    'all' {
        Invoke-Migrate
        Invoke-Fixture
        Start-RpcWorkers
        # RoadRunner workers perform PDO/descriptor warm-up before accepting
        # gRPC traffic. Give all five processes a bounded startup window so a
        # probe run immediately after `all` is reproducible.
        Start-Sleep -Seconds 5
        Show-Status
    }
}
