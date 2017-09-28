<?php

namespace App\Helpers\ExerciseConfig\Pipeline\Box;

use App\Exceptions\ExerciseConfigException;
use App\Helpers\ExerciseConfig\Pipeline\Box\Params\ConfigParams;
use App\Helpers\ExerciseConfig\Pipeline\Box\Params\TaskCommands;
use App\Helpers\ExerciseConfig\Pipeline\Ports\Port;
use App\Helpers\ExerciseConfig\Pipeline\Ports\PortMeta;
use App\Helpers\ExerciseConfig\Variable;
use App\Helpers\ExerciseConfig\VariableTypes;
use App\Helpers\JobConfig\Tasks\Task;


/**
 * Box which represents data source, mainly files.
 */
class FetchFilesBox extends Box
{
  /** Type key */
  public static $FETCH_TYPE = "fetch-files";
  public static $REMOTE_PORT_KEY = "remote";
  public static $INPUT_PORT_KEY = "input";
  public static $DEFAULT_NAME = "Fetch Pipeline Files";

  private static $initialized = false;
  private static $defaultInputPorts;
  private static $defaultOutputPorts;

  /**
   * Static initializer.
   */
  public static function init() {
    if (!self::$initialized) {
      self::$initialized = true;
      self::$defaultInputPorts = array(
        new Port((new PortMeta)->setName(self::$REMOTE_PORT_KEY)->setType(VariableTypes::$REMOTE_FILE_ARRAY_TYPE))
      );
      self::$defaultOutputPorts = array(
        new Port((new PortMeta)->setName(self::$INPUT_PORT_KEY)->setType(VariableTypes::$FILE_ARRAY_TYPE))
      );
    }
  }


  /**
   * DataInBox constructor.
   * @param BoxMeta $meta
   */
  public function __construct(BoxMeta $meta) {
    parent::__construct($meta);
  }


  /**
   * Get type of this box.
   * @return string
   */
  public function getType(): string {
    return self::$FETCH_TYPE;
  }

  /**
   * Get default input ports for this box.
   * @return array
   */
  public function getDefaultInputPorts(): array {
    self::init();
    return self::$defaultInputPorts;
  }

  /**
   * Get default output ports for this box.
   * @return array
   */
  public function getDefaultOutputPorts(): array {
    self::init();
    return self::$defaultOutputPorts;
  }

  /**
   * Get default name of this box.
   * @return string
   */
  public function getDefaultName(): string {
    return self::$DEFAULT_NAME;
  }


  /**
   * Compile box into set of low-level tasks.
   * @return array
   * @throws ExerciseConfigException in case of compilation error
   */
  public function compile(): array {

    // remote file which should be downloaded from file-server
    $remoteVariable = $this->getInputPortValue(self::$REMOTE_PORT_KEY);
    $variable = $this->getOutputPortValue(self::$INPUT_PORT_KEY);

    // both variable and input variable are arrays
    if (count($remoteVariable->getValue()) !== count($variable->getValue())) {
      throw new ExerciseConfigException(sprintf("Different count of remote variables and local variables in box '%s'", self::$FETCH_TYPE));
    }

    $remoteFiles = $remoteVariable->getValue();
    $files = $variable->getPrefixedValue(ConfigParams::$SOURCE_DIR);

    // general foreach for both local and remote files
    $tasks = [];
    for ($i = 0; $i < count($files); ++$i) {
      $task = new Task();

      // remote file has to have fetch task
      $task->setCommandBinary(TaskCommands::$FETCH);
      $task->setCommandArguments([
        $remoteFiles[$i],
        $files[$i]
      ]);

      // add task to result
      $tasks[] = $task;
    }
    return $tasks;
  }

}