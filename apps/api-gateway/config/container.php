<?php

declare(strict_types=1);

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Psr\Container\ContainerInterface;

return (new Webman\Container())->addDefinitions([
    AuthServiceClient::class => static fn (): AuthServiceClient => new AuthServiceClient(
        getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051'
    ),
    UserServiceClient::class => static fn (): UserServiceClient => new UserServiceClient(
        getenv('GRPC_USER_SERVICE') ?: 'user-service:50052'
    ),
    CreateWsTicketAction::class => static fn (ContainerInterface $c): CreateWsTicketAction => new CreateWsTicketAction(
        $c->get(AuthServiceClient::class), $c->get(UserServiceClient::class)
    ),
    AuthController::class => static fn (ContainerInterface $c): AuthController => new AuthController(
        $c->get(AuthServiceClient::class), $c->get(CreateWsTicketAction::class)
    ),
    UserController::class => static fn (ContainerInterface $c): UserController => new UserController(
        $c->get(UserServiceClient::class)
    ),
    PersonaController::class => static fn (ContainerInterface $c): PersonaController => new PersonaController(
        $c->get(UserServiceClient::class)
    ),
    HealthController::class => static fn (ContainerInterface $c): HealthController => new HealthController([
        'auth_service' => $c->get(AuthServiceClient::class),
        'user_service' => $c->get(UserServiceClient::class),
    ]),
    AuthMiddleware::class => static fn (ContainerInterface $c): AuthMiddleware => new AuthMiddleware(
        $c->get(AuthServiceClient::class)
    ),
]);
