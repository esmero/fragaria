<?php

namespace Drupal\fragaria;


use Twig\Environment;
use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;
use Drupal\fragaria\DataCiteService;


/**
 * Class TwigExtension.
 *
 * @package Drupal\fragaria
 */
class TwigExtension extends AbstractExtension {

  /**
   * @var \Drupal\Fragaria\DataCiteService
   */
  private DataCiteService $dataCiteService;


  /**
   * Constructs \Drupal\fragaria\TwigExtension
   *
   * @param DataCiteService $dataCiteService
   *   The DataCite Service.
   */
  public function __construct(DataCiteService $dataCiteService) {
    $this->dataCiteService = $dataCiteService;
  }


  /**
   * @inheritDoc
   */
  public function getFunctions() {
    return [
      new TwigFunction('sbf_datacite',
        $this->processAndValidateDataCite(...))
    ];
  }

  public function processAndValidateDataCite($original_data, $event = NULL) {
    $valid_events = array_combine(DataCiteService::DATACITE_FRAGARIA_VALID_EVENTS, DataCiteService::DATACITE_FRAGARIA_VALID_EVENTS);
    $datacite_value = NULL;
    if (is_string($event)) {
      //Lower case BC people are messy
      $event = strtolower($event);
    }
    if ($this->dataCiteService->isActive()) {
      $api = $this->dataCiteService->getActiveAPI();
      if ($original_data['ap:tasks']['ap:fragaria'][$api] ?? NULL) {
        $datacite_value['ap:tasks']['ap:fragaria'][$api] = $original_data['ap:tasks']['ap:fragaria'][$api] ?? NULL;
      }
      if (!empty($datacite_value)) {
        // Validate it
        $tobevalidated = $datacite_value['ap:tasks']['ap:fragaria'][$api] ?? [];
        $validated = $this->dataCiteService->validateApTask($tobevalidated);
        if ($validated['valid'] && $validated['status'] && $validated['doi']) {
          // delete any previous errors
          unset($datacite_value['error']);
         // we don't check on "draft" status bc any event is possible on a draft
          if ($validated['status'] == "registered") {
            unset($valid_events['draft']);
            unset($valid_events['delete']);
          }
          elseif ($validated['status'] == "findable") {
            unset($valid_events['draft']);
            unset($valid_events['delete']);
            unset($valid_events['publish']);
          }
          // Now our valid events after prunning. If no event we don't need to do anything here.
          if ($event && in_array($event, $valid_events)) {
            $datacite_value['ap:tasks']['ap:fragaria'][$api]['event'] = $event;
          }
        }
        else {
          // Invalid state? If previous data is invalid, we can try with the DOI itself
          // and no state/no event. This will trigger a "fetch" current status
          // from the API/remote using the
          if ($validated['doi']) {
            $datacite_value['ap:tasks']['ap:fragaria'][$api] = [];
            $datacite_value['ap:tasks']['ap:fragaria'][$api]['doi'] = $validated['doi'];
          }
        }
      }
      else {
        // If no previous data, validate that the event is valid
        if ($event && ($event !== "delete") && in_array($event, $valid_events)) {
          $datacite_value['ap:tasks']['ap:fragaria'][$api]['event'] = $event;
        }
      }
    }
    return $datacite_value;
  }
}
