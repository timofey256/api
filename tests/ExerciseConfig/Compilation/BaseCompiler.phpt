<?php

include '../../bootstrap.php';

use App\Helpers\ExerciseConfig\Compilation\BoxesCompiler;
use App\Helpers\ExerciseConfig\Compilation\BoxesOptimizer;
use App\Helpers\ExerciseConfig\Compilation\BoxesSorter;
use App\Helpers\ExerciseConfig\Compilation\BaseCompiler;
use App\Helpers\ExerciseConfig\Compilation\PipelinesMerger;
use App\Helpers\ExerciseConfig\Compilation\TestDirectoriesResolver;
use App\Helpers\ExerciseConfig\Compilation\VariablesResolver;
use App\Helpers\ExerciseConfig\Loader;
use App\Helpers\ExerciseConfig\Pipeline\Box\BoxService;
use App\Helpers\ExerciseConfig\Pipeline\Box\GccCompilationBox;
use App\Helpers\ExerciseConfig\Pipeline\Box\JudgeNormalBox;
use App\Helpers\ExerciseConfig\Pipeline\Box\Params\ConfigParams;
use App\Helpers\ExerciseConfig\Pipeline\Box\Params\LinuxSandbox;
use App\Helpers\ExerciseConfig\Pipeline\Box\Params\TaskType;
use App\Model\Repository\Pipelines;
use Tester\Assert;


/**
 * All special cases should be resolved in appropriate tests. This test is only
 * integration test of all compiler components and if it is working as expected.
 */
class TestBaseCompiler extends Tester\TestCase
{
  /** @var BaseCompiler */
  private $compiler;
  /** @var Loader */
  private $loader;

  /** @var Mockery\Mock | Pipelines */
  private $mockPipelines;

  /** @var Mockery\Mock | \App\Model\Entity\Pipeline */
  private $mockCompilationPipeline;
  /** @var Mockery\Mock | \App\Model\Entity\Pipeline */
  private $mockTestPipeline;

  /** @var Mockery\Mock | \App\Model\Entity\PipelineConfig */
  private $mockCompilationPipelineConfig;
  /** @var Mockery\Mock | \App\Model\Entity\PipelineConfig */
  private $mockTestPipelineConfig;


  private static $exerciseConfig = [
    "environments" => [ "envA", "envB" ],
    "tests" => [
      "testA" => [
        "pipelines" => [
          [ "name" => "compilationPipeline", "variables" => [] ],
          [ "name" => "testPipeline", "variables" => [ [ "name" => "expected_output", "type" => "remote-file", "value" => "expected.A.out" ] ] ]
        ],
        "environments" => [
          "envA" => [ "pipelines" => [] ],
          "envB" => [ "pipelines" => [] ]
        ]
      ],
      "testB" => [
        "pipelines" => [
          [ "name" => "compilationPipeline", "variables" => [] ],
          [ "name" => "testPipeline", "variables" => [ [ "name" => "expected_output", "type" => "remote-file", "value" => "expected.B.out" ] ] ]
        ],
        "environments" => [
          "envA" => [
            "pipelines" => [
              [ "name" => "compilationPipeline", "variables" => [] ],
            ]
          ],
          "envB" => [
            "pipelines" => []
          ]
        ]
      ]
    ]
  ];
  private static $envVariablesTable = [
    [ "name" => "source_files", "type" => "file[]", "value" => ["source"] ]
  ];
  private static $environment = "envA";
  private static $compilationPipeline = [
    "variables" => [
      ["name" => "source_files", "type" => "file[]", "value" => ["source"]],
      ["name" => "binary_file", "type" => "file", "value" => "a.out"]
    ],
    "boxes" => [
      [
        "name" => "source",
        "type" => "data-in",
        "portsIn" => [],
        "portsOut" => [
          "in-data" => ["type" => "file[]", "value" => "source_files"]
        ]
      ],
      [
        "name" => "compilation",
        "type" => "gcc",
        "portsIn" => [
          "source-files" => ["type" => "file[]", "value" => "source_files"]
        ],
        "portsOut" => [
          "binary-file" => ["type" => "file", "value" => "binary_file"]
        ]
      ],
      [
        "name" => "output",
        "type" => "data-out",
        "portsIn" => [
          "out-data" => ["type" => "file", "value" => "binary_file"]
        ],
        "portsOut" => []
      ]
    ]
  ];
  private static $testPipeline = [
    "variables" => [
      [ "name" => "binary_file", "type" => "file", "value" => "a.out" ],
      [ "name" => "expected_output", "type" => "file", "value" => "expected.out" ],
      [ "name" => "actual_output", "type" => "file", "value" => "actual.out" ]
    ],
    "boxes" => [
      [
        "name" => "binary",
        "type" => "data-in",
        "portsIn" => [],
        "portsOut" => [
          "in-data" => [ "type" => "file", "value" => "binary_file" ]
        ]
      ],
      [
        "name" => "test",
        "type" => "data-in",
        "portsIn" => [],
        "portsOut" => [
          "in-data" => [ "type" => "file", "value" => "expected_output" ]
        ]
      ],
      [
        "name" => "run",
        "type" => "elf-exec",
        "portsIn" => [
          "args" => [ "type" => "string[]", "value" => "" ],
          "stdin" => [ "type" => "file", "value" => "" ],
          "binary-file" => [ "type" => "file", "value" => "binary_file" ],
          "input-files" => [ "type" => "file[]", "value" => "" ]
        ],
        "portsOut" => [
          "stdout" => [ "type" => "file", "value" => "" ],
          "output-file" => [ "type" => "file", "value" => "actual_output" ]
        ]
      ],
      [
        "name" => "judge",
        "type" => "judge-normal",
        "portsIn" => [
          "actual-output" => [ "type" => "file", "value" => "actual_output" ],
          "expected-output" => [ "type" => "file", "value" => "expected_output" ]
        ],
        "portsOut" => []
      ]
    ]
  ];
  private static $limits = [
    [ // groupA
      "testA" => [
        "memory" => 123,
        "wall-time" => 456.0
      ]
    ],
    [ // groupB
      "testA" => [
        "memory" => 654,
        "wall-time" => 321.0
      ]
    ]
  ];


  /**
   * TestExerciseConfigCompiler constructor.
   */
  public function __construct() {

    // mock entities and stuff
    $this->mockCompilationPipelineConfig = Mockery::mock(\App\Model\Entity\PipelineConfig::class);
    $this->mockCompilationPipeline = Mockery::mock(\App\Model\Entity\Pipeline::class);
    $this->mockCompilationPipeline->shouldReceive("getPipelineConfig")->andReturn($this->mockCompilationPipelineConfig);
    $this->mockTestPipelineConfig = Mockery::mock(\App\Model\Entity\PipelineConfig::class);
    $this->mockTestPipeline = Mockery::mock(\App\Model\Entity\Pipeline::class);
    $this->mockTestPipeline->shouldReceive("getPipelineConfig")->andReturn($this->mockTestPipelineConfig);

    $this->mockCompilationPipelineConfig->shouldReceive("getParsedPipeline")->andReturn(self::$compilationPipeline);
    $this->mockTestPipelineConfig->shouldReceive("getParsedPipeline")->andReturn(self::$testPipeline);

    $this->mockPipelines = Mockery::mock(Pipelines::class);
    $this->mockPipelines->shouldReceive("findOrThrow")->with("compilationPipeline")->andReturn($this->mockCompilationPipeline);
    $this->mockPipelines->shouldReceive("findOrThrow")->with("testPipeline")->andReturn($this->mockTestPipeline);

    // constructions of compiler components
    $this->loader = new Loader(new BoxService());
    $variablesResolver = new VariablesResolver();
    $pipelinesMerger = new PipelinesMerger($this->mockPipelines, $this->loader, $variablesResolver);
    $boxesSorter = new BoxesSorter();
    $boxesOptimizer = new BoxesOptimizer();
    $boxesCompiler = new BoxesCompiler();
    $testDirectoriesResolver = new TestDirectoriesResolver();
    $this->compiler = new BaseCompiler($pipelinesMerger, $boxesSorter,
      $boxesOptimizer, $boxesCompiler, $testDirectoriesResolver);
  }

  public function testCorrect() {
    $exerciseConfig = $this->loader->loadExerciseConfig(self::$exerciseConfig);
    $environmentConfigVariables = $this->loader->loadVariablesTable(self::$envVariablesTable);
    $limits = [
      "groupA" => $this->loader->loadExerciseLimits(self::$limits[0]),
      "groupB" => $this->loader->loadExerciseLimits(self::$limits[1])
    ];
    $jobConfig = $this->compiler->compile($exerciseConfig, $environmentConfigVariables, $limits, self::$environment, []);

    // check general properties
    Assert::equal(["groupA", "groupB"], $jobConfig->getSubmissionHeader()->getHardwareGroups());
    Assert::equal(9, $jobConfig->getTasksCount());

    ////////////////////////////////////////////////////////////////////////////
    // check order of all tasks and right attributes
    //

    $testAMkdir = $jobConfig->getTasks()[0];
    Assert::equal("testA..mkdir.1", $testAMkdir->getId());
    Assert::equal(1, $testAMkdir->getPriority());
    Assert::count(0, $testAMkdir->getDependencies());
    Assert::equal("mkdir", $testAMkdir->getCommandBinary());
    Assert::equal([ConfigParams::$SOURCE_DIR . "testA"], $testAMkdir->getCommandArguments());
    Assert::null($testAMkdir->getType());
    Assert::equal("testA", $testAMkdir->getTestId());
    Assert::null($testAMkdir->getSandboxConfig());

    $testBMkdir = $jobConfig->getTasks()[1];
    Assert::equal("testB..mkdir.2", $testBMkdir->getId());
    Assert::equal(2, $testBMkdir->getPriority());
    Assert::count(0, $testBMkdir->getDependencies());
    Assert::equal("mkdir", $testBMkdir->getCommandBinary());
    Assert::equal([ConfigParams::$SOURCE_DIR . "testB"], $testBMkdir->getCommandArguments());
    Assert::null($testBMkdir->getType());
    Assert::equal("testB", $testBMkdir->getTestId());
    Assert::null($testBMkdir->getSandboxConfig());

    $testATestTask = $jobConfig->getTasks()[2];
    Assert::equal("testA.testPipeline.test.3", $testATestTask->getId());
    Assert::equal(3, $testATestTask->getPriority());
    Assert::count(0, $testATestTask->getDependencies());
    Assert::equal("fetch", $testATestTask->getCommandBinary());
    Assert::equal(["expected.A.out", ConfigParams::$SOURCE_DIR . "testA/expected.out"], $testATestTask->getCommandArguments());
    Assert::null($testATestTask->getType());
    Assert::equal("testA", $testATestTask->getTestId());
    Assert::null($testATestTask->getSandboxConfig());

    $testASourceTask = $jobConfig->getTasks()[3];
    Assert::equal("testA.compilationPipeline.source.4", $testASourceTask->getId());
    Assert::equal(4, $testASourceTask->getPriority());
    Assert::count(0, $testASourceTask->getDependencies());
    Assert::equal("cp", $testASourceTask->getCommandBinary());
    Assert::equal([ConfigParams::$SOURCE_DIR . "source", ConfigParams::$SOURCE_DIR . "testA/source"], $testASourceTask->getCommandArguments());
    Assert::null($testASourceTask->getType());
    Assert::equal("testA", $testASourceTask->getTestId());
    Assert::null($testASourceTask->getSandboxConfig());

    $testACompilationTask = $jobConfig->getTasks()[4];
    Assert::equal("testA.compilationPipeline.compilation.5", $testACompilationTask->getId());
    Assert::equal(5, $testACompilationTask->getPriority());
    Assert::count(1, $testACompilationTask->getDependencies());
    Assert::equal([$testASourceTask->getId()], $testACompilationTask->getDependencies());
    Assert::equal(GccCompilationBox::$GCC_BINARY, $testACompilationTask->getCommandBinary());
    Assert::equal([ConfigParams::$EVAL_DIR . "testA/source", "-o", ConfigParams::$EVAL_DIR . "testA/a.out"], $testACompilationTask->getCommandArguments());
    Assert::equal(TaskType::$INITIATION, $testACompilationTask->getType());
    Assert::equal("testA", $testACompilationTask->getTestId());
    Assert::notEqual(null, $testACompilationTask->getSandboxConfig());
    Assert::equal(LinuxSandbox::$ISOLATE, $testACompilationTask->getSandboxConfig()->getName());
    Assert::count(0, $testACompilationTask->getSandboxConfig()->getLimitsArray());

    $testARunTask = $jobConfig->getTasks()[5];
    Assert::equal("testA.testPipeline.run.6", $testARunTask->getId());
    Assert::equal(6, $testARunTask->getPriority());
    Assert::count(1, $testARunTask->getDependencies());
    Assert::equal([$testACompilationTask->getId()], $testARunTask->getDependencies());
    Assert::equal(ConfigParams::$EVAL_DIR . "testA/a.out", $testARunTask->getCommandBinary());
    Assert::equal([], $testARunTask->getCommandArguments());
    Assert::equal(TaskType::$EXECUTION, $testARunTask->getType());
    Assert::equal("testA", $testARunTask->getTestId());
    Assert::notEqual(null, $testARunTask->getSandboxConfig());
    Assert::equal(LinuxSandbox::$ISOLATE, $testARunTask->getSandboxConfig()->getName());
    Assert::equal(ConfigParams::$EVAL_DIR . "testA", $testARunTask->getSandboxConfig()->getChdir());
    Assert::count(2, $testARunTask->getSandboxConfig()->getLimitsArray());
    Assert::equal(123, $testARunTask->getSandboxConfig()->getLimits("groupA")->getMemoryLimit());
    Assert::equal(456.0, $testARunTask->getSandboxConfig()->getLimits("groupA")->getWallTime());
    Assert::equal(654, $testARunTask->getSandboxConfig()->getLimits("groupB")->getMemoryLimit());
    Assert::equal(321.0, $testARunTask->getSandboxConfig()->getLimits("groupB")->getWallTime());

    $testAJudgeTask = $jobConfig->getTasks()[6];
    Assert::equal("testA.testPipeline.judge.7", $testAJudgeTask->getId());
    Assert::equal(7, $testAJudgeTask->getPriority());
    Assert::count(2, $testAJudgeTask->getDependencies());
    Assert::equal([$testATestTask->getId(), $testARunTask->getId()], $testAJudgeTask->getDependencies());
    Assert::equal(ConfigParams::$JUDGES_DIR . JudgeNormalBox::$JUDGE_NORMAL_BINARY, $testAJudgeTask->getCommandBinary());
    Assert::equal([ConfigParams::$EVAL_DIR . "testA/expected.out", ConfigParams::$EVAL_DIR . "testA/actual.out"], $testAJudgeTask->getCommandArguments());
    Assert::equal(TaskType::$EVALUATION, $testAJudgeTask->getType());
    Assert::equal("testA", $testAJudgeTask->getTestId());
    Assert::notEqual(null, $testAJudgeTask->getSandboxConfig());
    Assert::equal(LinuxSandbox::$ISOLATE, $testAJudgeTask->getSandboxConfig()->getName());
    Assert::equal(ConfigParams::$EVAL_DIR . "testA", $testAJudgeTask->getSandboxConfig()->getChdir());
    Assert::count(0, $testAJudgeTask->getSandboxConfig()->getLimitsArray());

    $testBSourceTask = $jobConfig->getTasks()[7];
    Assert::equal("testB.compilationPipeline.source.8", $testBSourceTask->getId());
    Assert::equal(8, $testBSourceTask->getPriority());
    Assert::count(0, $testBSourceTask->getDependencies());
    Assert::equal("cp", $testBSourceTask->getCommandBinary());
    Assert::equal([ConfigParams::$SOURCE_DIR . "source", ConfigParams::$SOURCE_DIR . "testB/source"], $testBSourceTask->getCommandArguments());
    Assert::null($testBSourceTask->getType());
    Assert::equal("testB", $testBSourceTask->getTestId());
    Assert::null($testBSourceTask->getSandboxConfig());

    $testBCompilationTask = $jobConfig->getTasks()[8];
    Assert::equal("testB.compilationPipeline.compilation.9", $testBCompilationTask->getId());
    Assert::equal(9, $testBCompilationTask->getPriority());
    Assert::count(1, $testBCompilationTask->getDependencies());
    Assert::equal([$testBSourceTask->getId()], $testBCompilationTask->getDependencies());
    Assert::equal(GccCompilationBox::$GCC_BINARY, $testBCompilationTask->getCommandBinary());
    Assert::equal([ConfigParams::$EVAL_DIR . "testB/source", "-o", ConfigParams::$EVAL_DIR . "testB/a.out"], $testBCompilationTask->getCommandArguments());
    Assert::equal(TaskType::$INITIATION, $testBCompilationTask->getType());
    Assert::equal("testB", $testBCompilationTask->getTestId());
    Assert::notEqual(null, $testBCompilationTask->getSandboxConfig());
    Assert::equal(LinuxSandbox::$ISOLATE, $testBCompilationTask->getSandboxConfig()->getName());
    Assert::count(0, $testBCompilationTask->getSandboxConfig()->getLimitsArray());
  }

}

# Testing methods run
$testCase = new TestBaseCompiler();
$testCase->run();
