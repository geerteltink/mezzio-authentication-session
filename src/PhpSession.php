<?php

/**
 * @see       https://github.com/mezzio/mezzio-authentication-session for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-authentication-session/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-authentication-session/blob/master/LICENSE.md New BSD License
 */

namespace Mezzio\Authentication\Session;

use Mezzio\Authentication\AuthenticationInterface;
use Mezzio\Authentication\UserInterface;
use Mezzio\Authentication\UserRepositoryInterface;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PhpSession implements AuthenticationInterface
{
    /**
     * @var UserRepositoryInterface
     */
    protected $repository;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var ResponseInterface
     */
    protected $responsePrototype;

    /**
     * Constructor
     *
     * @param UserRepositoryInterface $repository
     * @param array $config
     * @param ResponseInterface $responsePrototype
     */
    public function __construct(
        UserRepositoryInterface $repository,
        array $config,
        ResponseInterface $responsePrototype
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->responsePrototype = $responsePrototype;
    }

    /**
     * {@inheritDoc}
     * @todo Refactor to use mezzio-session
     */
    public function authenticate(ServerRequestInterface $request) : ?UserInterface
    {
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        if (! $session) {
            throw Exception\MissingSessionContainerException::create();
        }

        if ($session->has(UserInterface::class)) {
            return $this->createUserFromSession($session);
        }

        if ('POST' !== strtoupper($request->getMethod())) {
            return null;
        }

        $params = $request->getParsedBody();
        $username = $this->config['username'] ?? 'username';
        $password = $this->config['password'] ?? 'password';
        if (! isset($params[$username]) || ! isset($params[$password])) {
            return null;
        }

        $user = $this->repository->authenticate(
            $params[$username],
            $params[$password]
        );

        if (null !== $user) {
            $session->set(UserInterface::class, [
                'username' => $user->getUsername(),
                'role' => $user->getUserRole(),
            ]);
            $session->regenerate();
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responsePrototype
            ->withHeader(
                'Location',
                $this->config['redirect']
            )
            ->withStatus(301);
    }

    /**
     * Create a UserInterface instance from the session data.
     *
     * mezzio-session does not serialize PHP objects directly. As such,
     * we need to create a UserInterface instance based on the data stored in
     * the session instead.
     */
    private function createUserFromSession(SessionInterface $session) : ?UserInterface
    {
        $userInfo = $session->get(UserInterface::class);
        if (! is_array($userInfo) || ! isset($userInfo['username'])) {
            return null;
        }

        return new class ($userInfo) implements UserInterface {
            private $role;

            private $username;

            public function __construct(array $userInfo)
            {
                $this->username = $userInfo['username'];
                $this->role = $userInfo['role'] ?? '';
            }

            public function getUsername() : string
            {
                return $this->username;
            }

            public function getUserRole() : string
            {
                return $this->role;
            }
        };
    }
}
