<?php

namespace App\Exception; 

class HttpBasicAuthException extends UnauthorizedException {

  public function getAdditionalHttpHeaders() {
    return array_merge(
      parent::getAdditionalHttpHeaders(),
      [ "WWW-Authenticate" => 'Basic realm="ReCodEx"' ]
    );
  }

}
