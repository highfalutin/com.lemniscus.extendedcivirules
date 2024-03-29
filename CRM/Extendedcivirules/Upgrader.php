<?php

use CRM_Extendedcivirules_ExtensionUtil as E;

/**
 * This class manages the installation, upgrading, etc of the Extended CiviRules extension.
 */
class CRM_Extendedcivirules_Upgrader extends CRM_Extendedcivirules_Upgrader_Base {

  public function install() {
    CRM_Civirules_Utils_Upgrader::insertTriggersFromJson(E::path('json/triggers.json'));
    CRM_Civirules_Utils_Upgrader::insertActionsFromJson(E::path('json/actions.json'));
    CRM_Civirules_Utils_Upgrader::insertConditionsFromJson(E::path('json/conditions.json'));
  }

  public function uninstall() {
    $entities = civicrm_api3('Entity', 'get');
    if (!in_array('CiviRuleTrigger', $entities['values'])) {
      return;
    }
    $this->uninstallFromJson('CiviRuleTrigger', 'json/triggers.json');
    $this->uninstallFromJson('CiviRuleAction', 'json/actions.json');
    $this->uninstallFromJson('CiviRuleCondition', 'json/conditions.json');
  }

  protected function uninstallFromJson($entity, $filePath): void {
    $items = json_decode(file_get_contents(E::path($filePath)), TRUE);
    foreach ($items as $item) {
      civicrm_api3($entity, 'get', [
        'class_name' => $item['class_name'],
        "api.$entity.delete" => [],
      ]);
    }
  }

  public function upgrade_1001() {
    $result = civicrm_api3('CiviRuleCondition', 'get', [
      'class_name' => 'CRM_CivirulesConditions_Contribution_IsRecurring',
    ]);
    $conditions = $result['values'] ?? [];

    while (count($conditions) > 1) {
      $toDelete = array_pop($conditions);
      civicrm_api('CiviRuleCondition', 'delete', [
        'id' => $toDelete['id']
      ]);
    }

    return TRUE;
  }

  public function upgrade_1002() {
    $result = civicrm_api3('CiviRuleAction', 'get', [
      'class_name' => 'CRM_CivirulesActions_Contribution_FinancialType',
    ]);
    $actions = $result['values'] ?? [];

    while (count($actions) > 1) {
      $toDelete = array_pop($actions);
      civicrm_api('CiviRuleAction', 'delete', [
        'id' => $toDelete['id']
      ]);
    }

    return TRUE;
  }

}
