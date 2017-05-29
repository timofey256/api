<?php

namespace App\Helpers\ExerciseConfig;
use Symfony\Component\Yaml\Yaml;
use JsonSerializable;


/**
 * High-level configuration exercise config holder.
 */
class ExerciseConfig implements JsonSerializable {

  /** Key for the tests item */
  const TESTS_KEY = "tests";

  /** @var array tests indexed by test name */
  protected $tests = array();


  /**
   * Get associative array of tests.
   * @return array
   */
  public function getTests(): array {
    return $this->tests;
  }

  /**
   * Get test for the given test name.
   * @param string $name
   * @return Test|null
   */
  public function getTest(string $name): ?Test {
    return $this->tests[$name];
  }

  /**
   * Add test into this holder.
   * @param Test $test
   * @return $this
   */
  public function addTest(Test $test): ExerciseConfig {
    $this->tests[$test->getName()] = $test;
    return $this;
  }

  /**
   * Remove test according to given test identification.
   * @param string $id
   * @return $this
   */
  public function removeTest(string $id): ExerciseConfig {
    unset($this->tests[$id]);
    return $this;
  }


  /**
   * Creates and returns properly structured array representing this object.
   * @return array
   */
  public function toArray(): array {
    $data = [];

    $data[self::TESTS_KEY] = array();
    foreach ($this->tests as $test) {
      $data[self::TESTS_KEY][] = $test->toArray();
    }
    return $data;
  }

  /**
   * Serialize the config.
   * @return string
   */
  public function __toString(): string {
    return Yaml::dump($this->toArray());
  }

  /**
   * Enable automatic serialization to JSON
   * @return array
   */
  public function jsonSerialize() {
    return $this->toArray();
  }
}
