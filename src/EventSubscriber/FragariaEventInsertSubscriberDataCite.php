<?php

namespace Drupal\fragaria\EventSubscriber;

use Drupal\Core\Url;
use Drupal\fragaria\DataCiteService;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\strawberryfield\EventSubscriber\StrawberryfieldEventInsertSubscriber;

/**
 *  Event subscriber for Data Cite on ADO Insery event.
 */
class FragariaEventInsertSubscriberDataCite extends StrawberryfieldEventInsertSubscriber {

  use StringTranslationTrait;

  /**
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
   * Method called when Event occurs.
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function onEntityInsert(StrawberryfieldCrudEvent $event) {
    if ($this->dataCiteService->isActive()) {
      /* @var $entity \Drupal\node\Entity\Node */
      $entity = $event->getEntity();
      // How do we know this is a new Entity?
      // We compare Creation and Change time. Should be ==
      // Let's check what the URL here is
      // IF use do_uuid is enabled and the URL is already that, don't do any updates via the API
      if ($entity->getCreatedTime() == $entity->getChangedTime() && $this->dataCiteService->getUrlType() == "canonical") {
        // Still "/do/uuid" could be the canonical. So let's avoid an extra call to DataCite by checking
        $ado_url = Url::fromRoute('<front>')->setAbsolute(FALSE);
        $ado_url = $ado_url->toString() . "/do/" . $entity->uuid();
        $canonical_ado_url = $entity->toUrl('canonical', ['absolute' => FALSE])
          ->toString();
        if ($ado_url != $canonical_ado_url) {
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
              $datacite_value = $full['ap:tasks']['ap:fragaria'][$api] ?? NULL;
              if ($datacite_value) {
                $parsed_datacite = $this->dataCiteService->validateApTask($datacite_value);
                // Because of function Signature we will cast to array just in case
                // Should be a status there. If not present we already have an error,
                // But could be error too!
                if ($parsed_datacite['valid'] && (!empty($parsed_datacite['doi']) && ($parsed_datacite['status'] != 'error'))) {
                  // Now with this data in hand we can start the workflow.
                  $data_wrapper_update = $this->dataCiteService::DATACITE_DATA_WRAPPER;
                  $data_wrapper_update['data']['attributes']['url'] = $canonical_ado_url;
                  $response = $this->dataCiteService->updateDOI($data_wrapper_update, $parsed_datacite['doi']);
                  if (!$response[0]) {
                    $message = $this->t('DOI @doi URL post Creation update (because of canonical URL being not a do/{uuid}) failed for ADO with UUID @UUID.',
                      [
                        '@uuid' => $entity->uuid(),
                        '@doi' => $parsed_datacite['doi'],
                      ]);
                    $this->loggerFactory->get($this->dataCiteService)
                      ->error($message);
                  }
                }
              }
            }
          }
        }
        $current_class = get_called_class();
        $event->setProcessedBy($current_class, TRUE);
      }
    }
  }
}