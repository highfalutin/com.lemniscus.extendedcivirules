<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;

/**
 * @group headless
 */
class CRM_Extendedcivirules_TriggerTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface {

  public static CRM_Extendedcivirules_ArrayLogger $logger;

  private array $createdEntities = [];

  private static int $membershipTypeId;
  private static array $createdRules = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();

    self::$logger = new CRM_Extendedcivirules_ArrayLogger();

    self::$createdRules['contribution'] = self::setUpContributionRule();
    self::$createdRules['membershipPayment'] = self::setUpMembershipPaymentRule();

    self::$membershipTypeId = \Civi\Api4\MembershipType::create(FALSE)
      ->setValues(
        [
          "domain_id" => 1,
          "name" => "Foo",
          "member_of_contact_id" => 1,
          "financial_type_id:name" => "Member Dues",
          "minimum_fee" => 0.0,
          "duration_unit" => "year",
          "duration_interval" => 1,
          "period_type" => "rolling",
          "visibility" => "Public",
          "auto_renew" => FALSE,
          "is_active" => TRUE,
        ]
      )->execute()->single()['id'];
  }

  public function setUp(): void {
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();

    foreach ($this->createdEntities as $type => $entities) {
      foreach ($entities as $entityId) {
        civicrm_api3($type, 'delete', ['id' => $entityId]);
      }
    }

    self::$logger->log = [];
  }

  public static function tearDownAfterClass(): void {
    civicrm_api3('CiviRuleRule', 'get', [
      'api.CiviRuleRule.delete' => [],
    ]);
  }

  public static function setUpContributionRule() {
    $triggerId = civicrm_api3('CiviRuleTrigger', 'getvalue', [
      'return' => "id",
      'name' => "new_contribution",
    ]);

    /** @var int $triggerId */
    $ruleId = self::setupRuleForTriggerId($triggerId);
    return $ruleId;
  }

  public static function setUpMembershipPaymentRule() {
    $triggerId = civicrm_api3('CiviRuleTrigger', 'getvalue', [
      'return' => "id",
      'name' => "new_membership_payment_extended",
    ]);

    /** @var int $triggerId */
    $ruleId = self::setupRuleForTriggerId($triggerId);
    return $ruleId;
  }

  public static function setupRuleForTriggerId(int $triggerId): int {
    $rule = civicrm_api3('CiviRuleRule', 'create', [
      'sequential' => 1,
      'label' => "x",
      'name' => "test_x",
      'trigger_id' => $triggerId,
      'is_debug' => 1,
    ])['values'][0];

    civicrm_api3('CiviRuleRuleCondition', 'create', [
      'rule_id' => $rule['id'],
      'condition_id' => civicrm_api3('CiviRuleCondition', 'getvalue', [
        'return' => "id",
        'name' => "field_value_comparison",
      ]),
      'condition_params' => serialize([
        'operator' => 'is empty',
        'value' => '',
        'multi_value' =>
          [
            0 => '',
          ],
        'entity' => 'Contribution',
        'field' => 'cancel_reason',
        'original_data' => 0,
      ]),
    ]);

    return $rule['id'];
  }

  public static function addThankYouDateActionToRule($id, string $yyyymmdd): void {
    civicrm_api3('CiviRuleRuleAction', 'create', [
      'rule_id' => $id,
      'action_id' => civicrm_api3('CiviRuleAction', 'getvalue', [
        'return' => "id",
        'name' => 'set_contribution_thank_date',
      ]),
      'action_params' => serialize([
        'thank_you_date_radio' => 2,
        'thank_you_date' => $yyyymmdd,
        'thank_you_time' => 0,
      ]),
    ]);
  }

  public function hook_civirules_logger(\Psr\Log\LoggerInterface &$logger = NULL) {
    if (empty($logger)) {
      $logger = self::$logger;
    }
  }

  public function testCoreTriggerIsCalled() {
    $contributionId = \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', 1)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', 1)
      ->execute()->single()['id'];

    $this->createdEntities['Contribution'][] = $contributionId;

    $log = self::$logger->log;
    self::assertNotEmpty($log, 'This sometimes happens on the first run '
      . 'of the test suite after setUpHeadless() rebuilds the environment. Just '
      . 'run it again.');
    self::assertEquals(
      self::$createdRules['contribution'],
      $log[0]['context']['rule_id']);
    self::assertEquals($contributionId, $log[0]['context']['entity_id']);
  }

  public function testExtendedTriggerIsCalled() {
    self::addThankYouDateActionToRule(
      self::$createdRules['contribution'], '19991231');
    self::addThankYouDateActionToRule(
      self::$createdRules['membershipPayment'], '20000101');

    $contributionId = \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', 1)
      ->addValue('financial_type_id', 1)
      ->addValue('total_amount', 1)
      ->execute()->single()['id'];

    $this->createdEntities['Contribution'][] = $contributionId;

    $modifiedContribution = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->execute()->single();

    self::assertEquals(
      '1999-12-31 00:00:00', $modifiedContribution['thankyou_date']);

    $membershipId = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => self::$membershipTypeId,
      'contact_id' => 1,
    ])['values'][0]['id'];

    $this->createdEntities['Membership'][] = $membershipId;

    $membershipPaymentId = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membershipId,
      'contribution_id' => $contributionId,
    ])['values'][0]['id'];

    // membershipPayment will be deleted along with contribution
    // $this->createdEntities['MembershipPayment'][] = $membershipPaymentId;

    $log = self::$logger->log;
    self::assertNotEmpty($log);
    self::assertEquals(
      self::$createdRules['membershipPayment'],
      $log[1]['context']['rule_id']);
    self::assertEquals($membershipPaymentId, $log[1]['context']['entity_id']);

    $modifiedContribution = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->execute()->single();

    self::assertEquals(
      '2000-01-01 00:00:00', $modifiedContribution['thankyou_date']);
  }

}
