<?php

include '../../bootstrap.php';

use App\Exceptions\ExerciseConfigException;
use App\Helpers\ExerciseConfig\Compilation\BoxesSorter;
use App\Helpers\ExerciseConfig\Compilation\Tree\MergeTree;
use App\Helpers\ExerciseConfig\Compilation\Tree\PortNode;
use App\Helpers\ExerciseConfig\Pipeline\Box\CustomBox;
use Tester\Assert;


class TestBoxesSorter extends Tester\TestCase
{
  /** @var BoxesSorter */
  private $sorter;

  public function __construct() {
    $this->sorter = new BoxesSorter();
  }


  public function testEmptyMergeTrees() {
    Assert::noError(function () {
      $this->sorter->sort(array());
    });
  }

  /**
   *  A -> B
   */
  public function testSimpleA() {
    // @todo
  }

  /**
   *  A -> B -> C
   */
  public function testSimpleB() {
    // @todo
  }

  /**
   *  A   B
   *   \ /
   *    C
   */
  public function testSimpleI() {
    // @todo
  }

  /**
   *    A
   *   / \
   *  B   C
   */
  public function testSimpleC() {
    // @todo
  }

  /**
   *    A
   *   / \
   *  B   C
   *       \
   *        D
   */
  public function testSimpleD() {
    // @todo
  }

  /**
   *    A
   *   / \
   *  B   C
   *     / \
   *    D   E
   */
  public function testSimpleE() {
    // @todo
  }

  /**
   *  A
   *   \
   *    B
   *   /|\
   *  C D E
   */
  public function testSimpleF() {
    // @todo
  }

  /**
   *    A
   *   / \
   *  B   C
   *   \ /
   *    D
   */
  public function testSimpleG() {
    // @todo
  }

  /**
   *    A
   *   / \
   *  B   C
   *   \ / \
   *    D   E
   */
  public function testSimpleH() {
    $A = new PortNode(new CustomBox());
    $B = new PortNode(new CustomBox());
    $C = new PortNode(new CustomBox());
    $D = new PortNode(new CustomBox());
    $E = new PortNode(new CustomBox());

    $A->addChild("AB", $B);
    $A->addChild("AC", $C);
    $B->addChild("BD", $D);
    $B->addParent("BA", $A);
    $C->addChild("CD", $D);
    $C->addChild("CE", $E);
    $C->addParent("CA", $A);
    $D->addParent("DB", $B);
    $D->addParent("DC", $C);
    $E->addParent("EC", $C);

    $tree = new MergeTree();
    $tree->addOtherNode($A)->addOtherNode($B)->addOtherNode($C)->addOtherNode($D)->addOtherNode($E);
    $treeArr = array($tree);

    Assert::noError(function () use ($treeArr) {
      $this->sorter->sort($treeArr);
    });

    // *** check order of nodes

  }

  /**
   *         A
   *        / \
   *  ---> B   D
   *  |     \ /
   *  |      C
   *  |     /
   *  ---- E
   */
  public function testCycleA() {
    $A = new PortNode(new CustomBox());
    $B = new PortNode(new CustomBox());
    $C = new PortNode(new CustomBox());
    $D = new PortNode(new CustomBox());
    $E = new PortNode(new CustomBox());

    $A->addChild("AB", $B);
    $A->addChild("AD", $D);
    $B->addChild("BC", $C);
    $B->addParent("BA", $A);
    $B->addParent("BE", $E);
    $C->addChild("CE", $E);
    $C->addParent("CB", $B);
    $C->addParent("CD", $D);
    $D->addChild("DC", $C);
    $D->addParent("DA", $A);
    $E->addChild("EB", $B);
    $E->addParent("EC", $C);

    $tree = new MergeTree();
    $tree->addOtherNode($A)->addOtherNode($B)->addOtherNode($C)->addOtherNode($D)->addOtherNode($E);
    $treeArr = array($tree);

    Assert::exception(function () use ($treeArr) {
      $this->sorter->sort($treeArr);
    }, ExerciseConfigException::class);
  }

  /**
   *  A -> B
   *  ^    |
   *  |    |
   *  D <- C
   */
  public function testCycleB() {
    $A = new PortNode(new CustomBox());
    $B = new PortNode(new CustomBox());
    $C = new PortNode(new CustomBox());
    $D = new PortNode(new CustomBox());

    $A->addChild("AB", $B);
    $A->addParent("AD", $D);
    $B->addChild("BC", $C);
    $B->addParent("BA", $A);
    $C->addChild("CD", $D);
    $C->addParent("CB", $B);
    $D->addChild("DA", $A);
    $D->addParent("DC", $C);

    $tree = new MergeTree();
    $tree->addOtherNode($A)->addOtherNode($B)->addOtherNode($C)->addOtherNode($D);
    $treeArr = array($tree);

    Assert::exception(function () use ($treeArr) {
      $this->sorter->sort($treeArr);
    }, ExerciseConfigException::class);
  }

}

# Testing methods run
$testCase = new TestBoxesSorter();
$testCase->run();
