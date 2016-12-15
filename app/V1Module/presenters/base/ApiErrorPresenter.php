<?php

namespace App\V1Module\Presenters;

use App\Exceptions\UnauthorizedException;
use App\Exceptions\WrongCredentialsException;
use App\Exceptions\ApiException;
use App\Exceptions as AE;
use App\Model\Repository\UserActions;

use Nette\Http\IResponse;
use Nette\Application\BadRequestException;

use Doctrine\DBAL\Exception\ConnectionException;

use Tracy\ILogger;

/**
 * The error presenter for the API module - all responses are served as JSONs with a fixed format.
 */
class ApiErrorPresenter extends \App\Presenters\BasePresenter {

  /**
   * @var ILogger
   * @inject
   */
  public $logger;

  /**
   * @var UserActions
   * @inject
   */
  public $userActions;

  /**
   * @param  Exception
   * @return void
   */
  public function renderDefault($exception) {
    // first let us log the whole error thingy
    $this->handleLogging($exception);

    if ($exception instanceof ApiException) {
      $this->handleAPIException($exception);
    } elseif ($exception instanceof BadRequestException) {
      $this->sendErrorResponse($exception->getCode(), "Bad Request");
    } elseif ($exception instanceof ConnectionException) {
      $this->sendErrorResponse(IResponse::S500_INTERNAL_SERVER_ERROR, "Database is offline");
    } else {
      $type = get_class($exception);
      $this->sendErrorResponse(IResponse::S500_INTERNAL_SERVER_ERROR, "Unexpected Error {$type}");
    }
  }

  /**
    * Send an error response based on a known type of exceptions - derived from ApiException
    * @param  ApiException $exception The exception which caused the error
    */
  protected function handleAPIException(ApiException $exception) {
    $res = $this->getHttpResponse();
    $additionalHeaders = $exception->getAdditionalHttpHeaders();
    foreach ($additionalHeaders as $name => $value) {
      $res->setHeader($name, $value);
    }
    $this->sendErrorResponse($exception->getCode(), $exception->getMessage());
  }

  /**
   * Simply logs given exception into standard logger. Some filtering or
   * further modifications can be engaged.
   * @param \Throwable $exception Exception which should be logged
   */
  protected function handleLogging(\Throwable $exception) {
    if ($exception instanceof BadRequestException) {
      // nothing to log here
    } else if ($exception instanceof UnauthorizedException
        || $exception instanceof WrongCredentialsException
        || $exception instanceof AE\BadRequestException) {
      $this->logger->log("HTTP code {$exception->getCode()}: {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}", 'access');
    } else {
      $this->logger->log($exception, ILogger::EXCEPTION);
    }
  }

  /**
    * Send a JSON response with a specific HTTP code
    * @param  int      $code    HTTP code of the response
    * @param  string   $msg     Human readable description of the error
    * @return void
    */
  protected function sendErrorResponse(int $code, string $msg) {
    // log the action done by the current user
    if ($this->getUser()->isLoggedIn()) {
      // determine the action name from the application request
      $req = $this->getRequest();
      $params = $req->getParameters();
      $action = isset($params[self::ACTION_KEY]) ? $params[self::ACTION_KEY] : self::DEFAULT_ACTION;
      unset($params[self::ACTION_KEY]);
      $fullyQualified = ':' . $req->getPresenterName() . ':' . $action;
      $this->userActions->log($fullyQualified, $params, $code, $msg);
    }

    // send the error message in the standard format
    $this->getHttpResponse()->setCode($code);
    $this->sendJson([
        "code"      => $code,
        "success"   => FALSE,
        "msg"       => $msg
    ]);
  }

}
