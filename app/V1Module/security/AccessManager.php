<?php

namespace App\Security;

use App\Model\Entity\User;
use App\Model\Repository\Users;

use App\Exceptions\ApiException;
use App\Exceptions\InvalidAccessTokenException;
use App\Exceptions\NoAccessTokenException;
use App\Exceptions\ForbiddenRequestException;

use Nette\Http\Request;
use Nette\Security\Identity;
use Nette\Utils\Strings;

use Firebase\JWT\JWT;
use DomainException;
use UnexpectedValueException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class AccessManager {

  /** @var Users  Users repository */
  protected $users;

  public function __construct(array $parameters, Users $users) {
    $this->users = $users;
    $this->parameters = $parameters;
  }

  /**
   * Extract user information from the 
   * @param   Request       $req    HTTP request
   * @return  Identity|NULL
   */
  public function getIdentity(Request $req) {
    try {
      $token = $this->getGivenAccessTokenOrThrow($req);
      $decodedToken = $this->decodeToken($token);
      $user = $this->getUser($decodedToken);
      $scopes = [];
      if (isset($decodedToken->scopes)) {
        $scopes = $decodedToken->scopes;
      }

      return new Identity($user->getId(), $user->getRole()->getId(), [ "info" => $user->jsonSerialize(), "token" => $token ]);
    } catch (ApiException $e) {
      return NULL; 
    }
  }

  /**
   * Parse and validate a JWT token and extract the payload.
   * @param string The potential JWT token
   * @return object The decoded payload
   * @throws InvalidAccessTokenException
   */
  public function decodeToken($token): AccessToken {
    JWT::$leeway = $this->parameters['leeway'];

    try {
      $decodedToken = JWT::decode($token, $this->getSecretVerificationKey(), $this->getAllowedAlgs());
    } catch (DomainException $e) {
      throw new InvalidAccessTokenException($token);
    } catch (UnexpectedValueException $e) {
      throw new InvalidAccessTokenException($token);
    } catch (ExpiredException $e) {
      throw new InvalidAccessTokenException($token);
    } catch (SignatureInvalidException $e) {
      throw new ForbiddenRequestException();
    } catch (BeforeValidException $e) {
      throw new InvalidAccessTokenException($token);
    }

    if (!isset($decodedToken->sub)) {
      throw new InvalidAccessTokenException($token);
    }

    return new AccessToken($decodedToken);
  }

  /**
   * @param   object $token   Valid JWT payload
   * @return  User
   */
  public function getUser(AccessToken $token): User {
    $user = $this->users->get($token->getUserId());
    if (!$user || $user->isAllowed() === FALSE) {
      throw new ForbiddenRequestException;
    }

    return $user;
  }

  /**
   * Issue a new JWT for the user with optional scopes and optional explicit expiration time.
   * @param   User     $user
   * @param   string[] $scopes   Array of scopes
   * @param   int      $exp      Expiration of the token in seconds
   * @return  string
   */
  public function issueToken(User $user, array $scopes = [], $exp = NULL) {
    if ($exp === NULL) {
      $exp = $this->parameters['expiration'];
    }

    $tokenPayload = [
      "iss" => $this->parameters['issuer'],
      "aud" => $this->parameters['audience'],
      "iat" => time(),
      "nbf" => time(),
      "exp" => time() + $exp,
      "sub" => $user->getId(),
      "scopes" => $scopes
    ];

    return JWT::encode($tokenPayload, $this->getSecretVerificationKey(), $this->getAlg());
  }

  private function getSecretVerificationKey() {
    return $this->parameters['verificationKey'];
  }

  private function getAllowedAlgs() {
    // allowed algs must be separated from the used algs - if the algorithm is changed in the future,
    // we must accept the older algorithm until all the old tokens expire
    return $this->parameters['allowedAlgorithms'];
  }

  private function getAlg() {
    return $this->parameters['usedAlgorithm'];
  }

  /**
   * Extract the access token from the request and throw an exception if there is none.
   * @return string|null  The access token parsed from the HTTP request, or FALSE if there is no access token.
   * @throws NoAccessTokenException
   */
  public function getGivenAccessTokenOrThrow(Request $request) {
    $token = $this->getGivenAccessToken($request);
    if ($token === NULL) {
      throw new NoAccessTokenException;
    }
    return $token;
  }

  /**
   * Extract the access token from the request.
   * @return string|null  The access token parsed from the HTTP request, or FALSE if there is no access token.
   */
  public function getGivenAccessToken(Request $request) {
    $accessToken = $request->getQuery("access_token");
    if($accessToken !== NULL) return $accessToken; // the token is specified in the URL

    // if the token is not in the URL, try to find the "Authorization" header with the bearer token
    $authorizationHeader = $request->getHeader("Authorization", NULL);
    $parts = Strings::split($authorizationHeader, "/ /");
    if(count($parts) === 2) {
      list($bearer, $accessToken) = $parts;
      if($bearer === "Bearer" && !Strings::contains($accessToken, " ")) {
        return $accessToken;
      }
    }

    return NULL; // there is no access token or it could not be parsed
  }

}
