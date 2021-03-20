<?php

namespace App\Helpers\ExternalLogin;

use App\Exceptions\BadRequestException;
use App\Exceptions\FrontendErrorMappings;
use App\Exceptions\InvalidStateException;
use App\Exceptions\WrongCredentialsException;
use App\Exceptions\InvalidExternalTokenException;
use App\Exceptions\InvalidArgumentException;
use App\Model\Entity\Instance;
use App\Model\Entity\User;
use App\Model\Repository\ExternalLogins;
use App\Model\Repository\Logins;
use App\Model\Repository\Users;
use App\Model\Repository\Instances;
use App\V1Module\Presenters\RegistrationPresenter;
use Nette\Utils\Arrays;
use Firebase\JWT\JWT;
use DomainException;
use UnexpectedValueException;

/**
 * Mapper of service identification to object instance
 */
class ExternalServiceAuthenticator
{
    /**
     * @var ExternalLogins
     */
    private $externalLogins;

    /**
     * @var Users
     */
    private $users;

    /**
     * @var Logins
     */
    private $logins;

    /**
     * @var Instances
     */
    public $instances;


    /**
     * @var array [ name => { jwtSecret, jwtAlgorithms, expiration } ]
     */
    private $authenticators = [];

    /**
     * Constructor with instantiation of all login services
     * @param ExternalLogins $externalLogins
     * @param Users $users
     * @param Logins $logins
     * @param array $authenticators (each one holding 'name' and 'jwtSecret')
     */
    public function __construct(
        array $authenticators,
        ExternalLogins $externalLogins,
        Users $users,
        Logins $logins,
        Instances $instances
    ) {
        $this->externalLogins = $externalLogins;
        $this->users = $users;
        $this->logins = $logins;
        $this->instances = $instances;

        foreach ($authenticators as $auth) {
            if (!empty($auth['name'] && !empty($auth['jwtSecret']))) {
                $this->authenticators[$auth['name']] = (object)[
                    'jwtSecret' => $auth['jwtSecret'],
                    'jwtAlgorithms' => Arrays::get($auth, 'jwtAlgorithms', ['HS256']),
                    'expiration' => Arrays::get($auth, 'expiration', 60),
                ];
            }
        }
    }

    /**
     * Verify that given authenticator exists.
     * @param string $name of the external authenticator
     * @return bool
     */
    public function hasAuthenticator(string $name): bool
    {
        return array_key_exists($name, $this->authenticators);
    }

    /**
     * Authenticate a user against given external authentication service.
     * The external identification may be paired with already existing account by email.
     * If instanceId is provided (either externally or in token), user will be registered if not present.
     * @param string $authName name of the external authenticator
     * @param string $token form the external authentication service
     * @param string|null $instanceId identifier of an instance where the user should be registered
     *                                (this may be overriden by a value in the token)
     * @return User
     * @throws BadRequestException
     * @throws WrongCredentialsException
     */
    public function authenticate(string $authName, string $token, string $instanceId = null): User
    {
        $user = null;
        $decodedToken = $this->decodeToken($authName, $token);

        // try to get the user by external ID
        try {
            $userData = new UserData($decodedToken); // this wrapping also performs some check
        } catch (InvalidArgumentException $e) {
            throw new InvalidExternalTokenException($token, $e->getMessage(), $e);
        }
        $user = $this->externalLogins->getUser($authName, $userData->getId());

        // try to match existing local user by email address
        if ($user === null) {
            $user = $this->tryConnect($authName, $userData);
        }

        // try to register a new user
        if ($user === null) {
            $instance = $this->getInstance($decodedToken, $instanceId);
            if ($instance) {
                $user = $userData->createEntity($instance);
                $this->users->persist($user);
                // connect the account to the login method
                $this->externalLogins->connect($authName, $user, $userData->getId());
            }
        }

        if ($user === null) {
            throw new WrongCredentialsException(
                "User authenticated through '$authName' has no corresponding account in ReCodEx.",
                FrontendErrorMappings::E400_105__WRONG_CREDENTIALS_EXTERNAL_USER_NOT_FOUND,
                ["service" => $authName]
            );
        }

        return $user;
    }

    /**
     * Attempt to decode and verify the token.
     * @param string $authName name of the external authenticator
     * @param string $token
     * @return object data of decoded token
     * @throws BadRequestException
     * @throws InvalidExternalTokenException
     */
    private function decodeToken(string $authName, string $token)
    {
        if (!$this->hasAuthenticator($authName)) {
            throw new BadRequestException("Unkown external authenticator name '$authName'.");
        }

        $authenticator = $this->authenticators[$authName];
        try {
            $decodedToken = JWT::decode($token, $authenticator->jwtSecret, $authenticator->jwtAlgorithms);
        } catch (DomainException $e) {
            throw new InvalidExternalTokenException($token, $e->getMessage(), $e);
        } catch (UnexpectedValueException $e) {
            throw new InvalidExternalTokenException($token, $e->getMessage(), $e);
        }

        if (empty($decodedToken->iat) || (int)$decodedToken->iat + (int)$authenticator->expiration < time()) {
            throw new InvalidExternalTokenException($token, 'Token has expired.');
        }

        return $decodedToken;
    }

    /**
     * Try connecting given external user to local ReCodEx user account by email.
     * @param string $authName
     * @param UserData $userData
     * @return User|null
     */
    private function tryConnect(string $authName, UserData $userData): ?User
    {
        $user = $this->users->getByEmail($userData->getMail());
        if ($user) {
            $this->externalLogins->connect($authName, $user, $userData->getId());
            // and also clear local account password just to be sure
            $this->logins->clearUserPassword($user);
        }
        return $user;
    }

    /**
     * Retrieve instance entity where the user should be registered
     * @param object $decodedToken which is searched for instanceId key
     * @param string $instanceId instance suggested externally
     */
    private function getInstance($decodedToken, string $instanceId = null): ?Instance
    {
        if (!empty($decodedToken->instanceId)) {
            $instanceId = $decodedToken->instanceId;
        }

        // fetch the enetity from DB
        if ($instanceId) {
            $instance = $this->instances->get($instanceId);
            if (!$instance) {
                return null;
            }
            return $instance;
        }

        // if there is only one instance in the system, let's use it as default
        $instances = $this->instances->findAll();
        if (count($instances) === 1) {
            return $instances[0];
        }

        return null;
    }
}
