<?php

use CRM_Extendedcivirules_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_Extendedcivirules_InstallTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public function testEntitiesAreInstalled() {
    $expected = [
      'CiviRuleTrigger' => ['new_membership_payment_extended'],
      'CiviRuleCondition' => [
        'contribution_is_membership_payment',
        'contribution_position_in_recurring_series',
        'membership_payment_ordinal',
      ],
      'CiviRuleAction' => ['contribution_source'],
    ];
    foreach ($expected as $entityType => $names) {
      foreach ($names as $name) {
        $result = civicrm_api3($entityType, 'get', [
          'name' => $name,
        ]);
        self::assertEquals(1, $result['count']);
      }
    }
  }

}
