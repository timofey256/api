<?php

include '../../bootstrap.php';

use App\Exceptions\ExerciseConfigException;
use App\Helpers\ExerciseConfig\Pipeline\Box\BoxService;
use App\Helpers\ExerciseConfig\Pipeline\Ports\FileArrayPort;
use App\Helpers\ExerciseConfig\Pipeline\Ports\FilePort;
use App\Helpers\ExerciseConfig\Pipeline\Ports\PortMeta;
use App\Helpers\ExerciseConfig\Pipeline\Ports\StringArrayPort;
use App\Helpers\ExerciseConfig\Pipeline\Ports\StringPort;
use Symfony\Component\Yaml\Yaml;
use Tester\Assert;
use App\Helpers\ExerciseConfig\Loader;

class TestPort extends Tester\TestCase
{
  static $config = [
    "type" => "string",
    "value" => "varValue"
  ];

  /** @var Loader */
  private $loader;

  public function __construct() {
    $this->loader = new Loader(new BoxService());
  }

  public function testIncorrectData() {
    Assert::exception(function () {
      $this->loader->loadPort("name", null);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadPort("name", []);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadPort("name", "hello");
    }, ExerciseConfigException::class);
  }

  public function testIncorrectTypes() {
    Assert::exception(function () {
      $this->loader->loadPort("name", ["type" => "strings", "value" => "varValue"]);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadPort("name", ["type" => "files", "value" => "varValue"]);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadPort("name", ["type" => "[]string", "value" => "varValue"]);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadPort("name", ["type" => "[]file", "value" => "varValue"]);
    }, ExerciseConfigException::class);
  }

  public function testCorrectTypes() {
    Assert::type(StringPort::class, $this->loader->loadPort("name", ["type" => "string", "value" => "val"]));
    Assert::type(StringPort::class, $this->loader->loadPort("name", ["type" => "StRiNg", "value" => "val"]));

    Assert::type(StringArrayPort::class, $this->loader->loadPort("name", ["type" => "string[]", "value" => "val"]));
    Assert::type(StringArrayPort::class, $this->loader->loadPort("name", ["type" => "StRiNg[]", "value" => "val"]));

    Assert::type(FilePort::class, $this->loader->loadPort("name", ["type" => "file", "value" => "val"]));
    Assert::type(FilePort::class, $this->loader->loadPort("name", ["type" => "FiLe", "value" => "val"]));

    Assert::type(FileArrayPort::class, $this->loader->loadPort("name", ["type" => "file[]", "value" => "val"]));
    Assert::type(FileArrayPort::class, $this->loader->loadPort("name", ["type" => "FiLe[]", "value" => "val"]));
  }

  public function testMissingType() {
    Assert::exception(function () {
      $this->loader->loadPort("name", [
        "value" => "hello"
      ]);
    }, ExerciseConfigException::class);
  }

  public function testMissingValue() {
    Assert::exception(function () {
      $this->loader->loadPort("name", [
        "type" => "string"
      ]);
    }, ExerciseConfigException::class);
  }

  public function testSerialization() {
    $deserialized = Yaml::parse((string)$this->loader->loadPort("name", self::$config));
    Assert::equal(self::$config, $deserialized);
  }

  public function testPortsOperations() {
    $port = new PortMeta;

    Assert::equal(null, $port->getName());
    Assert::equal(null, $port->getType());
    Assert::equal(null, $port->getVariable());

    $port->setName("name");
    $port->setType("file");
    $port->setVariable("value");

    Assert::equal("name", $port->getName());
    Assert::equal("file", $port->getType());
    Assert::equal("value", $port->getVariable());
  }

  public function testCorrect() {
    $port = $this->loader->loadPort("name", self::$config);
    Assert::equal("name", $port->getName());
    Assert::equal("string", $port->getType());
    Assert::equal("varValue", $port->getVariable());
  }

}

# Testing methods run
$testCase = new TestPort;
$testCase->run();
