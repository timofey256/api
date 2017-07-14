<?php

include '../../bootstrap.php';

use App\Exceptions\ExerciseConfigException;
use App\Helpers\ExerciseConfig\Pipeline\Box\BoxMeta;
use App\Helpers\ExerciseConfig\Pipeline\Box\BoxService;
use App\Helpers\ExerciseConfig\Pipeline\Box\DataBox;
use App\Helpers\ExerciseConfig\Pipeline\Box\JudgeNormalBox;
use App\Helpers\ExerciseConfig\Pipeline\Ports\FilePort;
use App\Helpers\ExerciseConfig\Pipeline\Ports\PortMeta;
use App\Helpers\ExerciseConfig\Pipeline\Ports\StringPort;
use Symfony\Component\Yaml\Yaml;
use Tester\Assert;
use App\Helpers\ExerciseConfig\Loader;

class TestBox extends Tester\TestCase
{
  static $config = [
    "name" => "file",
    "type" => "data",
    "portsIn" => [],
    "portsOut" => [ "data_file" => ['type' => 'file', 'value' => "out_data_file"] ]
  ];

  static $configJudge = [
    "name" => "eval",
    "type" => "judge-normal",
    "portsIn" => [
      "expected_output" => ['type' => 'file', 'value' => "exp"],
      "actual_output" => ['type' => 'file', 'value' => "act"]
    ],
    "portsOut" => [ "score" => ['type' => 'string', 'value' => "out_data_file"] ]
  ];


  /** @var Loader */
  private $loader;

  public function __construct() {
    $this->loader = new Loader(new BoxService());
  }

  public function testIncorrectData() {
    Assert::exception(function () {
      $this->loader->loadBox(null);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadBox([]);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $this->loader->loadBox("hello");
    }, ExerciseConfigException::class);
  }

  public function testIncorrectTypes() {
    Assert::exception(function () {
      $config = self::$config;
      $config["type"] = "datas";
      $this->loader->loadBox($config);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $config = self::$config;
      $config["type"] = "judgeNormal";
      $this->loader->loadBox($config);
    }, ExerciseConfigException::class);

    Assert::exception(function () {
      $config = self::$config;
      $config["type"] = "judge";
      $this->loader->loadBox($config);
    }, ExerciseConfigException::class);
  }

  public function testCorrectTypes() {
    $dataBox = self::$config;
    $dataBox["type"] = "data";
    Assert::type(DataBox::class, $this->loader->loadBox($dataBox));
    $dataBox["type"] = "DaTa";
    Assert::type(DataBox::class, $this->loader->loadBox($dataBox));

    $judgeNormalBox = self::$configJudge;
    $judgeNormalBox["type"] = "judge-normal";
    Assert::type(JudgeNormalBox::class, $this->loader->loadBox($judgeNormalBox));
    $judgeNormalBox["type"] = "JuDgE-nOrMaL";
    Assert::type(JudgeNormalBox::class, $this->loader->loadBox($judgeNormalBox));
  }

  public function testMissingType() {
    Assert::exception(function () {
      $this->loader->loadBox([
        "value" => "hello"
      ]);
    }, ExerciseConfigException::class);
  }

  public function testMissingValue() {
    Assert::exception(function () {
      $this->loader->loadBox([
        "type" => "string"
      ]);
    }, ExerciseConfigException::class);
  }

  public function testWrongPortType() {
    Assert::exception(function () {
      $this->loader->loadBox(
        [
          "name" => "file",
          "type" => "data",
          "portsIn" => [],
          "portsOut" => ["data_file" => ['type' => 'string', 'value' => "out_data_file"]]
        ]
      );
    }, ExerciseConfigException::class);
  }

  public function testSerialization() {
    $deserialized = Yaml::parse((string)$this->loader->loadBox(self::$config));
    Assert::equal(self::$config, $deserialized);
  }

  public function testBoxMetaOperations() {
    $boxMeta = new BoxMeta;

    Assert::equal(null, $boxMeta->getName());
    Assert::equal(null, $boxMeta->getType());
    Assert::count(0, $boxMeta->getInputPorts());
    Assert::count(0, $boxMeta->getOutputPorts());

    $boxMeta->setName("newBoxName");
    $boxMeta->setType("newBoxType");

    Assert::equal("newBoxName", $boxMeta->getName());
    Assert::equal("newBoxType", $boxMeta->getType());
  }

  public function testPortsOperations() {
    $boxMeta = new BoxMeta;
    $portMeta = new PortMeta;
    $portMeta->setName("newlyAddedPort");
    $port = new StringPort($portMeta);

    $boxMeta->addInputPort($port);
    $boxMeta->addOutputPort($port);
    Assert::count(1, $boxMeta->getInputPorts());
    Assert::count(1, $boxMeta->getOutputPorts());

    $boxMeta->removeInputPort("non-existant");
    $boxMeta->removeOutputPort("non-existant");
    Assert::count(1, $boxMeta->getInputPorts());
    Assert::count(1, $boxMeta->getOutputPorts());

    $boxMeta->removeInputPort("newlyAddedPort");
    $boxMeta->removeOutputPort("newlyAddedPort");
    Assert::count(0, $boxMeta->getInputPorts());
    Assert::count(0, $boxMeta->getOutputPorts());
  }

  public function testCorrect() {
    $box = $this->loader->loadBox(self::$config);
    Assert::type(DataBox::class, $box);
    Assert::equal("file", $box->getName());
    Assert::count(0, $box->getInputPorts());
    Assert::count(1, $box->getOutputPorts());
    Assert::true(array_key_exists("data_file", $box->getOutputPorts()));

    /** @var PortMeta $port */
    $port = $box->getOutputPorts()["data_file"];
    Assert::type(FilePort::class, $port);
    Assert::equal("data_file", $port->getName());
    Assert::equal("out_data_file", $port->getVariable());
  }

}

# Testing methods run
$testCase = new TestBox;
$testCase->run();