<?php
use CRM_MembershipExtras_Service_MembershipInstallmentsHandler as MembershipInstallmentsHandler;
use CRM_MembershipExtras_Service_InstallmentReceiveDateCalculator as InstallmentReceiveDateCalculator;
use CRM_MembershipExtras_Service_MoneyUtilities as MoneyUtilities;

/**
 * Renews a payment plan with multiple installments.
 */
class CRM_MembershipExtras_Job_OfflineAutoRenewal_MultipleInstallmentPlan extends CRM_MembershipExtras_Job_OfflineAutoRenewal_PaymentPlan {

  /**
   * @inheritdoc
   */
  public function renew() {
    $this->createRecurringContribution();
    $this->renewPaymentPlanMemberships();
    $this->buildLineItemsParams();
    $this->setTotalAndTaxAmount();
    $this->recordPaymentPlanFirstContribution();

    $installmentsHandler = new MembershipInstallmentsHandler(
      $this->newRecurringContributionID
    );
    $installmentsHandler->createRemainingInstalmentContributionsUpfront();
  }

  /**
   * Renews the current membership recurring contribution by creating a new one
   * based on its data.
   *
   * The new recurring contribution will then be set to be the current recurring
   * contribution.
   */
  private function createRecurringContribution() {
    $currentRecurContribution = $this->currentRecurringContribution;
    $paymentProcessorID = !empty($currentRecurContribution['payment_processor_id']) ? $currentRecurContribution['payment_processor_id'] : NULL;

    $installmentReceiveDateCalculator = new InstallmentReceiveDateCalculator($currentRecurContribution);
    $this->paymentPlanStartDate = $installmentReceiveDateCalculator->calculate($currentRecurContribution['installments'] + 1);
    $paymentInstrumentName = $this->getPaymentMethodNameFromItsId($currentRecurContribution['payment_instrument_id']);

    $newRecurringContribution = civicrm_api3('ContributionRecur', 'create', [
      'sequential' => 1,
      'contact_id' => $currentRecurContribution['contact_id'],
      'amount' => 0,
      'currency' => $currentRecurContribution['currency'],
      'frequency_unit' => $currentRecurContribution['frequency_unit'],
      'frequency_interval' => $currentRecurContribution['frequency_interval'],
      'installments' => $currentRecurContribution['installments'],
      'contribution_status_id' => 'Pending',
      'is_test' => $currentRecurContribution['is_test'],
      'auto_renew' => 1,
      'cycle_day' => $currentRecurContribution['cycle_day'],
      'payment_processor_id' => $paymentProcessorID,
      'financial_type_id' => $currentRecurContribution['financial_type_id'],
      'payment_instrument_id' => $paymentInstrumentName,
      'start_date' => $this->paymentPlanStartDate,
    ])['values'][0];

    CRM_MembershipExtras_Service_CustomFieldsCopier::copy(
      $currentRecurContribution['id'],
      $newRecurringContribution['id'],
      'ContributionRecur'
    );
    $this->updateFieldsLinkingPeriods($currentRecurContribution['id'], $newRecurringContribution['id']);
    $this->copyRecurringLineItems($currentRecurContribution, $newRecurringContribution);
    $this->updateRecurringContributionAmount($newRecurringContribution['id']);

    // The new recurring contribution is now the current one.
    $this->newRecurringContribution = $newRecurringContribution;
    $this->newRecurringContributionID = $newRecurringContribution['id'];
  }

  /**
   * Obtains payment method name, given its ID.
   *
   * @param int $paymentMethodId
   *
   * @return array
   */
  private function getPaymentMethodNameFromItsId($paymentMethodId) {
    return civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'name',
      'option_group_id' => 'payment_instrument',
      'value' => $paymentMethodId,
    ]);
  }

  /**
   * Uses given ID's to set 'previous period' on new payment plan, and 'next
   * period' on current payment plan.
   *
   * @param int $currentContributionID
   * @param int $nextContributionID
   */
  private function updateFieldsLinkingPeriods($currentContributionID, $nextContributionID) {
    $previousPeriodFieldID = $this->getCustomFieldID('related_payment_plan_periods', 'previous_period');
    $nextPeriodFieldID = $this->getCustomFieldID('related_payment_plan_periods', 'next_period');

    civicrm_api3('ContributionRecur', 'create', [
      'id' => $currentContributionID,
      'custom_' . $nextPeriodFieldID => $nextContributionID,
    ]);

    civicrm_api3('ContributionRecur', 'create', [
      'id' => $nextContributionID,
      'custom_' . $previousPeriodFieldID => $currentContributionID,
    ]);
  }

  /**
   * Creates copies of all line items set to auto-renew in previous recurring
   * contribution and associates them with the new recurring contribution.
   *
   * @param array $currentContribution
   * @param array $nextContribution
   */
  private function copyRecurringLineItems($currentContribution, $nextContribution) {
    $recurringLineItems = $this->getRecurringContributionLineItemsToBeRenewed($currentContribution['id']);

    if (count($recurringLineItems)) {
      foreach ($recurringLineItems as $lineItem) {
        unset($lineItem['id']);
        $lineItem['unit_price'] = $this->calculateLineItemUnitPrice($lineItem);
        $lineItem['line_total'] = MoneyUtilities::roundToCurrencyPrecision($lineItem['unit_price'] * $lineItem['qty']);
        $lineItem['tax_amount'] = $this->calculateLineItemTaxAmount($lineItem['line_total'], $lineItem['financial_type_id']);

        $newLineItem = civicrm_api3('LineItem', 'create', $lineItem);
        CRM_MembershipExtras_BAO_ContributionRecurLineItem::create([
          'contribution_recur_id' => $nextContribution['id'],
          'line_item_id' => $newLineItem['id'],
          'start_date' => $nextContribution['start_date'],
          'auto_renew' => 1,
        ]);
      }
    }
  }

  /**
   * @inheritdoc
   */
  protected function getRecurringContributionLineItemsToBeRenewed($recurringContributionID) {
    $lineItems = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $recurringContributionID,
      'auto_renew' => 1,
      'is_removed' => 0,
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1],
      ],
    ]);

    if (!$lineItems['count']) {
      return [];
    }

    $result = [];
    foreach ($lineItems['values'] as $line) {
      $lineData = $line['api.LineItem.getsingle'];
      $result[] =  $lineData;
    }

    return $result;
  }

  /**
   * @inheritdoc
   */
  protected function getNewPaymentPlanActiveLineItems() {
    $lineItems = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->newRecurringContributionID,
      'is_removed' => 0,
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1],
      ],
    ]);

    if (!$lineItems['count']) {
      return [];
    }

    $result = [];
    foreach ($lineItems['values'] as $line) {
      $lineData = $line['api.LineItem.getsingle'];
      $result[] =  $lineData;
    }

    return $result;
  }

  /**
   * @inheritdoc
   */
  protected function calculateRecurringContributionTotalAmount($recurringContributionID) {
    $totalAmount = 0;

    $result = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $recurringContributionID,
      'start_date' => ['IS NOT NULL' => 1],
      'is_removed' => 0,
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1],
      ],
      'options' => ['limit' => 0],
    ]);

    if ($result['count'] > 0) {
      foreach ($result['values'] as $lineItemData) {
        $totalAmount += $lineItemData['api.LineItem.getsingle']['line_total'];
        $totalAmount += $lineItemData['api.LineItem.getsingle']['tax_amount'];
      }
    }

    return MoneyUtilities::roundToCurrencyPrecision($totalAmount);
  }

}
