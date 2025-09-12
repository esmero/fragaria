<?php

namespace Drupal\Fragaria\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventPresaveSubscriber;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\fragaria\DataCiteService;


/**
 * Event subscriber for Data Cite on ADO presave event.
 */
class FragariaEventPresaveSubscriberDataCite extends StrawberryfieldEventPresaveSubscriber {

  use StringTranslationTrait;

  /**
   * Run almost at the end.
   *
   * @var int
   */
  protected static $priority = -1100;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * @var \Drupal\Fragaria\DataCiteService
   */
  private DataCiteService $dataCiteService;

  /**
   * FragariaEventPresaveSubscriberDataCite constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\Session\AccountInterface $account
   * @param \Drupal\Fragaria\DataCiteService $data_cite_service
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $account,
    DataCiteService $data_cite_service
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->account = $account;
    $this->dataCiteService = $data_cite_service;
  }

  /**
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   */
  public function onEntityPresave(StrawberryfieldCrudEvent $event) {
    if ($this->dataCiteService->isActive()) {
      /* @var $entity \Drupal\node\Entity\Node */
      $entity = $event->getEntity();
      $sbf_fields = $event->getFields();
      $api = $this->dataCiteService->getActiveAPI();
      foreach ($sbf_fields as $field_name) {
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $field = $entity->get($field_name);
        /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
        // This will try with any possible match.
        foreach ($field->getIterator() as $delta => $itemfield) {
          /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
          $full = $itemfield->provideDecoded(TRUE);
          $previous_datacite_value = NULL;
          // Only if not new, we have to check the pre-save version for datacite metadata
          // We need this to avoid the user deleting a registered or findable one.
          if (!$entity->isNew()) {
            $full_original = $entity->original->get($field_name)
              ->get($delta)
              ->provideDecoded(TRUE);

            $previous_datacite_value = $full_original['ap:tasks']['ap:fragaria'][$api] ?? NULL;
            // Because of function Signature we will cast to array just in case
            $previous_datacite_value = (array) $previous_datacite_value;
            unset($full_original);
          }
          // Now with this data in hand we can start the workflow.
          $ap_task_from_workflow = $this->dataCiteService->evaluateWorkflow($entity,$full,$previous_datacite_value);
          if ($ap_task_from_workflow !== NULL) {
            $full['ap:tasks']['ap:fragaria'][$api] = $ap_task_from_workflow;
            if (!$itemfield->setMainValueFromArray((array) $full)) {
              $message = $this->t(
                'We could not persist DOI into ADO with UUID @uuid.',
                [
                  '@uuid' => $entity->uuid(),
                ]
              );
            }
          }
        }
      }
      $current_class = get_called_class();
      $event->setProcessedBy($current_class, TRUE);
    }
  }
}
