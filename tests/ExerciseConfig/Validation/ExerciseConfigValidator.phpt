<?php

$container = include '../../bootstrap.php';

use App\Exceptions\ExerciseConfigException;
use App\Exceptions\NotFoundException;
use App\Helpers\ExerciseConfig\Environment;
use App\Helpers\ExerciseConfig\ExerciseConfig;
use App\Helpers\ExerciseConfig\Loader;
use App\Helpers\ExerciseConfig\Pipeline\Box\BoxService;
use App\Helpers\ExerciseConfig\PipelineVars;
use App\Helpers\ExerciseConfig\Test;
use App\Helpers\ExerciseConfig\Validation\ExerciseConfigValidator;
use App\Model\Entity\Exercise;
use App\Model\Entity\ExerciseEnvironmentConfig;
use App\Model\Entity\Instance;
use App\Model\Entity\RuntimeEnvironment;
use App\Model\Entity\User;
use App\Model\Repository\Pipelines;
use Nette\DI\Container;
use Tester\Assert;


/**
 * @testCase
 */
class TestExerciseConfigValidator extends Tester\TestCase
{
  /**
   * @var Mockery\Mock | Pipelines
   */
  private $mockPipelines;

  /**
   * @var Mockery\Mock | \App\Model\Entity\Pipeline
   */
  private $mockPipelineEntity;

  /**
   * @var Mockery\Mock | \App\Model\Entity\PipelineConfig
   */
  private $mockPipelineConfigEntity;

  /**
   * @var ExerciseConfigValidator
   */
  private $validator;

  private $container;

  public function __construct(Container $container) {
    $this->mockPipelines = Mockery::mock(Pipelines::class);
    $this->validator = new ExerciseConfigValidator($this->mockPipelines, new Loader(new BoxService()));
    $this->container = $container;

    $this->mockPipelineConfigEntity = Mockery::mock(\App\Model\Entity\PipelineConfig::class);
    $this->mockPipelineConfigEntity->shouldReceive("getParsedPipeline")->andReturn([
      "boxes" => [],
      "variables" => []
    ]);

    $this->mockPipelineEntity = Mockery::mock(\App\Model\Entity\Pipeline::class);
    $this->mockPipelineEntity->shouldReceive("getPipelineConfig")->andReturn($this->mockPipelineConfigEntity);
  }


  public function testMissingEnvironment() {
    $exerciseConfig = new ExerciseConfig();
    $exercise = $this->createExerciseWithTwoEnvironments();

    Assert::exception(function () use ($exerciseConfig, $exercise) {
      $this->validator->validate($exerciseConfig, $exercise);
    }, ExerciseConfigException::class);
  }

  public function testDifferentEnvironments() {
    $exerciseConfig = new ExerciseConfig();
    $user = $this->getDummyUser();
    $exerciseConfig->addEnvironment("envA");
    $exerciseConfig->addEnvironment("envB");

    $exercise = Exercise::create($user);
    $envC = new RuntimeEnvironment("envC", "Env C", "C", ".c", "", "");
    $envD = new RuntimeEnvironment("envD", "Env D", "D", ".d", "", "");
    $exercise->addExerciseEnvironmentConfig(new ExerciseEnvironmentConfig($envC, "",  $user, NULL));
    $exercise->addExerciseEnvironmentConfig(new ExerciseEnvironmentConfig($envD, "",  $user, NULL));

    Assert::exception(function () use ($exerciseConfig, $exercise) {
      $this->validator->validate($exerciseConfig, $exercise);
    }, ExerciseConfigException::class);
  }

  public function testDifferentNumberOfEnvironments() {
    $exerciseConfig = new ExerciseConfig();
    $exercise = $this->createExerciseWithTwoEnvironments();
    $exerciseConfig->addEnvironment("envA");

    Assert::exception(function () use ($exerciseConfig, $exercise) {
      $this->validator->validate($exerciseConfig, $exercise);
    }, ExerciseConfigException::class);
  }

  public function testMissingDefaultPipeline() {
    $pipelineVars = new PipelineVars();
    $pipelineVars->setName("not existing pipeline");

    $test = new Test();
    $test->addPipeline($pipelineVars);

    $exerciseConfig = new ExerciseConfig();
    $exercise = $this->createExerciseWithSingleEnvironment();
    $exerciseConfig->addEnvironment("envA");
    $exerciseConfig->addTest("testA", $test);

    // setup mock pipelines
    $this->mockPipelines->shouldReceive("get")->withArgs(["not existing pipeline"])->andReturn(NULL);

    // missing in defaults
    Assert::exception(function () use ($exerciseConfig, $exercise) {
      $this->validator->validate($exerciseConfig, $exercise);
    }, ExerciseConfigException::class);
  }

  public function testMissingEnvironmentPipeline() {
    $existing = new PipelineVars();
    $notExisting = new PipelineVars();
    $existing->setName("existing pipeline");
    $notExisting->setName("not existing pipeline");

    $environment = new Environment();
    $environment->addPipeline($notExisting);

    $test = new Test();
    $test->addPipeline($existing);
    $test->addEnvironment("envA", $environment);

    $exerciseConfig = new ExerciseConfig();
    $exerciseConfig->addEnvironment("envA");
    $exerciseConfig->addTest("testA", $test);
    $exercise = $this->createExerciseWithSingleEnvironment();

    // setup mock pipelines
    $this->mockPipelines->shouldReceive("get")->withArgs(["not existing pipeline"])->andReturn(NULL);
    $this->mockPipelines->shouldReceive("get")->withArgs(["existing pipeline"])->andReturn($this->mockPipelineEntity);

    // missing in environments
    Assert::exception(function () use ($exerciseConfig, $exercise) {
      $this->validator->validate($exerciseConfig, $exercise);
    }, ExerciseConfigException::class);
  }

  public function testEmpty() {
    $exerciseConfig = new ExerciseConfig();
    $user = $this->getDummyUser();
    $exercise = Exercise::create($user);

    Assert::noError(
      function () use ($exerciseConfig, $exercise) {
        $this->validator->validate($exerciseConfig, $exercise);
      }
    );
  }

  public function testCorrect() {
    $existing = new PipelineVars();
    $existing->setName("existing pipeline");

    $environment = new Environment();
    $environment->addPipeline($existing);

    $test = new Test();
    $test->addPipeline($existing);
    $test->addEnvironment("envA", $environment);

    $exerciseConfig = new ExerciseConfig();
    $exerciseConfig->addEnvironment("envA");
    $exerciseConfig->addTest("testA", $test);

    $exercise = $this->createExerciseWithSingleEnvironment();

    // setup mock pipelines
    $this->mockPipelines->shouldReceive("get")->withArgs(["existing pipeline"])->andReturn($this->mockPipelineEntity);

    Assert::noError(
      function () use ($exerciseConfig, $exercise) {
        $this->validator->validate($exerciseConfig, $exercise);
      }
    );
  }

  /**
   * @return Exercise
   */
  private function createExerciseWithTwoEnvironments(): Exercise
  {
    $user = $this->getDummyUser();
    $exercise = Exercise::create($user);
    $envA = new RuntimeEnvironment("envA", "Env A", "A", ".a", "", "");
    $envB = new RuntimeEnvironment("envB", "Env B", "B", ".b", "", "");
    $exercise->addExerciseEnvironmentConfig(new ExerciseEnvironmentConfig($envA, "", $user, NULL));
    $exercise->addExerciseEnvironmentConfig(new ExerciseEnvironmentConfig($envB, "", $user, NULL));
    return $exercise;
  }

  /**
   * @return Exercise
   */
  private function createExerciseWithSingleEnvironment(): Exercise
  {
    $user = $this->getDummyUser();
    $exercise = Exercise::create($user);
    $envA = new RuntimeEnvironment("envA", "Env A", "A", ".a", "", "");
    $exercise->addExerciseEnvironmentConfig(new ExerciseEnvironmentConfig($envA, "", $user, NULL));
    return $exercise;
  }

  /**
   * @return User
   */
  private function getDummyUser(): User
  {
    $user = new User("", "", "", "", "", "", new Instance());
    return $user;
  }
}

# Testing methods run
$testCase = new TestExerciseConfigValidator($container);
$testCase->run();
