<?php

namespace Drupal\fragaria;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Render\RendererInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Drupal\Core\Config\ImmutableConfig;

class DataCiteService {

  use StringTranslationTrait;
  use MessengerTrait;
  use StringTranslationTrait;

  private const LOGGER_NAME = 'fragaria_datacite';

  /** @var string[]
   * 'register' os not a valid Data Cite event. But our action of
   *  A) Drafting (REST API without event) and the Updating the status OR
   *  B) Already Published and moving it to "hidden"
   *
   * 'delete' is not really a Valid Data Cite event, but we use to delete
   * a Draft. A Draft can be also deleted by removing the complete
   * ap:task -> ap:fragria -> datacite entry.
   *
   */
  private const DATACITE_FRAGARIA_VALID_EVENTS = ['draft','register','publish', 'delete'];
  private const DATACITE_FRAGARIA_VALID_STATUSES = ['draft','registered','findable', 'error'];

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The  Configuration settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;
  /**
   * The Drupal Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   * @param \GuzzleHttp\ClientInterface $httpClient
   * @param \Drupal\Core\Render\RendererInterface $renderer
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerFactory, AccountInterface $currentUser, ClientInterface $httpClient, RendererInterface $renderer) {
    $this->entityTypeManager = $entityTypeManager;
    $this->config = $configFactory->get(
      'fragaria.datacite'
    );
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory;
    $this->currentUser = $currentUser;
    $this->httpClient = $httpClient;
    $this->renderer = $renderer;
    // Setup the fragaria data cite monolog file logger
    $log = new Logger(static::LOGGER_NAME);
    $private_path = \Drupal::service('stream_wrapper_manager')->getViaUri('private://')->getDirectoryPath();
    $handler = new StreamHandler($private_path . '/fragaria/logs/datacite.log', Logger::DEBUG);
    $handler->setFormatter(new JsonFormatter());
    $log->pushHandler($handler);
    $this->loggerFactory->get(static::LOGGER_NAME)->setLoggers([[$log]]);
  }


  public function isActive(): bool {
    return (bool) $this->config->get('active') ?? FALSE;
  }

  /**
   * Returns the active URL and credentials
   *
   * @return array
   */
  public function getAPIUrlAndCredentials(): array {
    // @TODO. Make sure we also have password and user for both before returning
    if ($this->config->get('use_test_account')) {
      return ["https://api.test.datacite.org/dois/", $this->config->get('repository_fabrica_user_test'),  $this->config->get('repository_fabrica_password_test')];
    }
    else {
      return ["https://api.datacite.org/dois/", $this->config->get('repository_fabrica_user'),  $this->config->get('repository_fabrica_password')];
    }
  }

  /**
   * Returns the active URL and credentials
   *
   * @return string|null
   */
  public function getActiveAPI(): ?string {
    // @TODO. Make sure we also have password and user for both before returning
    if ($this->isActive()) {
      if ($this->config->get('use_test_account')) {
        return 'datacite_test';
      }
      else {
        return 'datacite';
      }
    } else {
      return NULL;
    }

  }

  /**
   * Fetches from DataCite a single DOI info,
   *
   * @param string $doi
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function fetchDOI(string $doi): array {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $sucessfull = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0] . trim($doi);
      $response = $this->httpClient->request('GET', $api_url, [
        'headers' => [
          'accept' => 'application/vnd.api+json',
          'authorization' => 'Basic '. base64_encode($api_url_and_credentials[1].':'.$api_url_and_credentials[2])
        ],
      ]);
      $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
      $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents()) : [];
    }
    return [$sucessfull, $response_encoded];
  }

  /**
   * @param array $data
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function requestDOI(array $data):array {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $sucessfull = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0];
      $response = $this->httpClient->request('POST', $api_url, [
        'body' => json_encode($data),
        'headers' => [
          'accept' => 'application/vnd.api+json',
          'authorization' => 'Basic '. base64_encode($api_url_and_credentials[1].':'.$api_url_and_credentials[2]),
          'content-type' => 'application/json',
        ],
      ]);
      $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
      $response_encoded = $sucessfull ? json_decode($response->getBody()->getContents()) : [];
    }
    return [$sucessfull, $response_encoded];
  }

  /**
   * @param array $data
   * @param string $doi
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function updateDOI(array $data, string $doi):array {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $sucessfull = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0].trim($doi);
      $response = $this->httpClient->request('PUT', $api_url, [
        'body' => json_encode($data),
        'headers' => [
          'accept' => 'application/vnd.api+json',
          'authorization' => 'Basic '. base64_encode($api_url_and_credentials[1].':'.$api_url_and_credentials[2]),
          'content-type' => 'application/json',
        ],
      ]);
      $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents()) : [];
    }
    return [$sucessfull, $response_encoded];
  }


  /**
   * Deletes (a draft) DOI from DataCite.
   *
   * @param string $doi
   *
   * @return boolean
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function deleteDOI(string $doi): bool {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $status = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0] . trim($doi);
      $response = $this->httpClient->request('DELET', $api_url, [
        'headers' => [
          'authorization' => 'Basic '. base64_encode($api_url_and_credentials[1].':'.$api_url_and_credentials[2])
        ],
      ]);
      $status = $response->getStatusCode() == 204;
    }
    return $status;
  }

  /**
   * This is the entry point for this service.
   *
   *  The reason we don't pass along the complete Pre-save data is bc we only
   *  Need the complete new data to update the ap:task value with a possible response from Data Cite
   *  Or, in the case of an invalid one and a valid previous one, that one
   *  Or, in the case of both invalid *or no previous one, a wipe out.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param array $fullvalues
   * @param array|null $previous_data_cite_value
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function evaluateWorkflow(ContentEntityInterface $entity, array $fullvalues, array|null $previous_data_cite_value):array {
    // Just in case.
    if ($this->isActive()) {
      // What we need.
      // A) Do we have ['ap:tasks']['ap:fragaria']['datacite'] ?
      // IF so, is it valid?
      // B) Do we have previous to save ['ap:tasks']['ap:fragaria']['datacite'] ?
      // IF so, is it valid?
      // C) Evaluate if old version (if any) is compatible with new version.
      //   1) If incompatible: old version IS valid, restore it but do not keep running anything. Just log.
      //   1) If compatible: Run the workflow/move the states/ Update the entity.
      // If data needs to be updated re-set $fullvalues and set the field again.
      $api = $this->getActiveAPI();
      $datacite_trigger = $fullvalues['ap:tasks']['ap:fragaria'][$api] ?? NULL;
      $workflow_status = [];
      $entity_status = TRUE;
      if ($entity->getEntityType()->isRevisionable() && !$entity->isLatestRevision()) {
        $entity_status = FALSE;
      }
      $entity_status =  $entity_status && $entity->isPublished();
      // Before calling anything
      if ($datacite_trigger == NULL && $previous_data_cite_value == NULL) {
        // Nothing to do here, nobody is requesting anything or APIs don't match
        // But we won't act on a non-active API.
        // TODO. Maybe write drush/route callbacks that allow to act on a non active API?
        // MMMM.
        return [];
      }

      if ($this->config->get('use_do_url')) {
          $ado_url = Url::fromRoute('<front>')->setAbsolute();
          $ado_url = $ado_url->toString()."/do/" . $entity->uuid();
      }
      else {
        $ado_url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }

      $calls = [];
      $ap_task_to_save = [];
      // Now validate both. We are going to use a decision matrix to decide how to proceed.
      // Super important. The only reason "event" from a previous version might be stored and passed around is IF
      // the status of that event was "error". We don't preserve the Last Event request, only the final status.
      $ap_task_passed_array = $this->validateApTask($datacite_trigger);
      $previous_ap_task_passed_array = $this->validateApTask($previous_data_cite_value);

      // Key 0 holds if Valid
      // Key 1 holds the Event. Previous data should never have an Event except if errored (so we can replay)
      // Key 2 holds status
      // Key 3 holds the DOI, if any.
      // @TODO. Make this very long chunk of nested if/else reusable method and simpler.
      // If both states are valid. Now check possible transitions
      if ($ap_task_passed_array[0] == $previous_ap_task_passed_array[0] && $ap_task_passed_array[0]) {
        // Most simple ones. No previous data
        // NO DOI (previous or new)
        if ($ap_task_passed_array[3] == NULL && $previous_ap_task_passed_array[3] == NULL) {
          if (($previous_ap_task_passed_array[2] == NULL) && $ap_task_passed_array[2] == 'draft') {
            $calls[] = ['api' => 'create', 'event' => NULL];
            // call API with empty event. NO DOI passed neither
          }
          elseif (($previous_ap_task_passed_array[2] == NULL) && $ap_task_passed_array[2] == 'register') {

            // OK this here is chained. How do I logically do that?
            $calls[] = ['api' => 'create', 'event' => NULL];
            $calls[] = ['api' => 'update', 'event' => 'register', 'doi' => NULL];
            // This requires two calls. First create a Draft. Once Drafted. Request a status update to registered.
          }
          elseif (($previous_ap_task_passed_array[2] == NULL) && $ap_task_passed_array[2] == 'publish') {

            $calls[] = ['api' => 'create', 'event' => 'publish'];
            // call API with publish event.
          }
          else {
            // What is else under NO DOI? Wrong combo of operations?
          }
        }
        elseif ($ap_task_passed_array[3] == NULL && $previous_ap_task_passed_array[3] !== NULL) {
          // the user might have manipulated the updated array. Wrong. But we had one before.
          $ap_task_to_save = $previous_ap_task_passed_array[3];
          if ($previous_ap_task_passed_array[2] == "error") {
            $ap_task_to_save[2] = NULL; // Unset ERROR status. Let the Event run in the future. Not in this run?
          }
          // Restore the old one. There might be ONLY one situation here that requires us to act differently.
          // IF the previous status was 'error'. But that should either resolve again in replaying?
        }
        // DOI IN NEW DATA
        elseif  ($ap_task_passed_array[3] !== NULL) {
          // If we have an ADO, the workflow combo restrictions come in place.
          // Deal first with the most complex scenario. The user is providing a MANUAL DOI, and we have no previous history of it.
          $doi = NULL;
          $doi_status = NULL;
          if ($previous_ap_task_passed_array[3] == NULL && $entity_status) {
            $check_new_doi = $this->fetchDOI($ap_task_passed_array[3]);
            if ($check_new_doi[0]) {
              $doi = $check_new_doi['data']['attributes']['doi'] ?? $doi;
              $doi_status = $check_new_doi['data']['attributes']['state'] ?? $doi_status;
              // To make this work, we will set the Old data to the known data from the API.
              $previous_ap_task_passed_array[2] = $doi_status;
              $previous_ap_task_passed_array[3] = $doi;
            }
          }
          elseif ($previous_ap_task_passed_array[3] !== NULL && $ap_task_passed_array[3] != $previous_ap_task_passed_array[3]) {
            // This is wrong. One DOI per ADO. If the user is trying to connect a new one to this, but our state says we already have one.
            // Means we need to read from the remote the original one, and decide based on that.
            // check if
            $check_original_doi = $this->fetchDOI($previous_ap_task_passed_array[3]);
            // I could check for URL, but if the user decided to use aliases and the alias changed I won't have a clue here.
            if ($check_original_doi[0]) {
              $doi = $check_original_doi['data']['attributes']['doi'] ?? $doi;
              $doi_status = $check_original_doi['data']['attributes']['state'] ?? $doi_status;
              // Nothing to be done here. But we should restore the old data though.
              // But no Event.
              $ap_task_to_save = [TRUE, NULL, $doi_status, $doi];
            }
            else {
              // Only if the original one does not exist. DOIs are expensive.
              // In this case we can actually keep evaluating.
              $check_new_doi = $this->fetchDOI($ap_task_passed_array[3]);
              if ($check_new_doi[0]) {
                $doi = $check_new_doi['data']['attributes']['doi'] ?? $doi;
                $doi_status = $check_new_doi['data']['attributes']['state'] ?? $doi_status;
                // To make this work, we will set the Old data to the known data from the API.
                $previous_ap_task_passed_array[2] = $doi_status;
                $previous_ap_task_passed_array[3] = $doi;
              }
            }
          }

          // Finally the expected/simpler one when users don't mess with task data. Previous and New have the same DOI.
          if ($previous_ap_task_passed_array[3] !== NULL && $ap_task_passed_array[3] == $previous_ap_task_passed_array[3]) {
            // Now we can finally evaluate if the event can be run
            if ($ap_task_passed_array[1] == "draft" && $previous_ap_task_passed_array[2] == "draft") {
              // Nothing to do other than Updating metadata.
              $calls[] = ['api' => 'update', 'event' => 'draft', 'doi' => $ap_task_passed_array[3]];
            }
            elseif ($ap_task_passed_array[1] == "register" && $previous_ap_task_passed_array[2] == "draft" && $entity_status) {
              // This requires an UPDATE status call.

              $calls[] = ['api' => 'update', 'event' => 'register', 'doi' => $ap_task_passed_array[3]];
            }
            elseif ($ap_task_passed_array[1] == "publish" && $previous_ap_task_passed_array[2] == "draft" && $entity_status) {

              $calls[] = ['api' => 'update', 'event' => 'publish', 'doi' => $ap_task_passed_array[3]];
              // This requires an UPDATE status call.
            }
            elseif ($ap_task_passed_array[1] == "publish" && $previous_ap_task_passed_array[2] == "registered" && $entity_status) {

              $calls[] = ['api' => 'update', 'event' => 'publish', 'doi' => $ap_task_passed_array[3]];
              // This requires an UPDATE status call.
            }
            elseif ($ap_task_passed_array[1] == "register" && $previous_ap_task_passed_array[2] == "findable" && $entity_status) {

              $calls[] = ['api' => 'update', 'event' => 'hide', 'doi' => $ap_task_passed_array[3]];
              // This requires an UPDATE status call to "hide" it.
            }
            elseif ($ap_task_passed_array[1] == "delete" && $previous_ap_task_passed_array[2] !== "draft") {
              // This is an error. And in that case we bail out.
            }
            elseif ($ap_task_passed_array[1] == "delete" && $previous_ap_task_passed_array[2] === "draft") {
              $calls[] = ['api' => 'delete', 'doi' => $ap_task_passed_array[3]];
            }
            else {
              // What is else here?
            }
          }
        }
        // IF No DOI but previous had a DOI

        else {
          // What is the else condition? @TODO. Re-Read your own code Diego!
        }
      }
      elseif ($previous_ap_task_passed_array[0] == TRUE && $ap_task_passed_array[0] == FALSE) {
        // Previous is OK, new one is not Valid.
        $ap_task_to_save = $previous_ap_task_passed_array[0];
      }
      else {
        // Both are Wrong. Nothing to do.
      }
      // So if the previous one is invalid and the new one is valid?
      // Should never happen but there are edge cases. e.g. the Structure was pushed into the ADO but
      // DataCite was not enabled. So it lingers around.
      $doi_prefix = $this->config->get('doi_prefix');
      if (count($calls) && $doi_prefix) {
        // Call the APIs.
        // Let's generate metadata first.

        $data_cite_metadata = $this->castADOtoDataCite($fullvalues, $entity, $workflow_status);
        if ($data_cite_metadata !== NULL) {
          $data_cite_metadata['url'] = $ado_url;
          $data_wrapper = [
            'data' => [
              'type' => 'dois',
              'attributes' => $data_cite_metadata
              ]
            ];
          $doi = NULL;
          foreach ($calls as $call) {
            if ($call['api'] == 'create') {
              $data_wrapper_create = $data_wrapper;
             if ($call['event']) {
               $data_wrapper_create['data']['attributes']['event'] = $call['event'];
             }
             $data_wrapper_create['data']['attributes']['prefix'] = $doi_prefix;
             $response = $this->requestDOI($data_wrapper_create);
             if ($response[0]) {
                 // DOI will  bet set by this one and reused in others, if any.
                $doi = $response['data']['attributes']['doi'];
                $status = $response['data']['attributes']['state'];
             }
            }
            elseif ($call['api'] == 'update') {
              $data_wrapper_update = $data_wrapper;
              $doi_update = $call['doi'] ?? $doi;
              if ($doi_update) {
                if ($call['event']) {
                  $data_wrapper_update['data']['attributes']['event'] = $call['event'];
                }
                $response = $this->updateDOI($data_wrapper_update, $doi_update);
                if ($response[0]) {
                  $doi = $response['data']['attributes']['doi'];
                  $status = $response['data']['attributes']['state'];
                }
              }
            }
            elseif ($call['api'] == 'delete')  {
              $doi_delete = $call['doi'] ?? $doi;
              $response = $this->deleteDOI($doi_delete);
              if ($response) {
               // remove the $ap_task. Deleted and done.
              }
            }

          }
        }
        else {
          // Wrong metadata.
        }
      }
    }



    // A new datacite_trigger being NULL is valid if we e.g. generated a Draft, and we want to delete it now.
    // But only IF there is a previous valid (with a DOI present) version in the pre-save.
    // Also, unpublished records can only have Drafts. Ok?
    // We don't make the transition automatically to Registered

    //@TODO reset $ap_task_to_save;
    return $ap_task_to_save;
  }


  public function validateDOI(string $doi) {
    if (str_starts_with($doi, $this->config->get('doi_prefix'))) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * @param array|null $datacite_metadata
   *    An array with the following values
   *      Boolean: Validation of basic structure
   *      Requested Event. Defaults to NULL
   *      Current Status: Defaults to NULL
   *      DOI if found. Defaults to NULL
   *
   * @return array
   */
  public function validateApTask(array|null $datacite_metadata):array {
    $valid = TRUE;
    $requested_event = NULL;
    $current_status = NULL;
    $DOI = NULL;
    // API needs to only match IF there is a 'status'. present. If new the request happens on the active one.
    // Here we assume there can be only ONE dataCite ID at the time. Not ONE per API.
    // NULL is valid. Like no data.
    if ($datacite_metadata == NULL) {
      return [TRUE, NULL, $current_status];
    }
    else {
      if (is_array($datacite_metadata) &&
        in_array(($datacite_metadata['event'] ?? NULL),static::DATACITE_FRAGARIA_VALID_EVENTS)) {
        $requested_event = $datacite_metadata['event'];
        // Here we know we have an event. The validation now is a bit deeper.
        // Status should only exist IF error/or if we have a DOI.
        // Note. Status might have been removed. Should not be the case, but we will
        // Entertain the idea that IF we do have an DOI, we can request via the REST API a status updated.
        if (isset($datacite_metadata['status'])) {
          if (in_array($datacite_metadata['status'], static::DATACITE_FRAGARIA_VALID_STATUSES)) {
            $current_status = $datacite_metadata['status'];
          }
          else {
            $valid = FALSE;
          }
        }
        if (isset($datacite_metadata['doi']) && is_string($datacite_metadata['doi'])) {
          $valid = $valid && $this->validateDOI($datacite_metadata['doi']);
          if ($valid) {
            $DOI = $datacite_metadata['doi'];
          }
        }
      }
      else {
        // No event. Which is valid because we delete the EVENT ONCE WE SET A STATUS.
        // SO A RE-SAVE using RAW JSON or a programmatic Update via VBO will not re-set an event?
        // To be safe here. We assume the opposite, that it is invalid.
        $valid = FALSE;
        // Here we demand a status/DOI combo.
        if (isset($datacite_metadata['status'])) {
          if (in_array($datacite_metadata['status'], static::DATACITE_FRAGARIA_VALID_STATUSES)) {
            $current_status = $datacite_metadata['status'];
            if (isset($datacite_metadata['doi']) && is_string($datacite_metadata['doi'])) {
              $valid = $this->validateDOI($datacite_metadata['doi']);
              if ($valid) {
                $DOI = $datacite_metadata['doi'];
              }
            }
          }
        }
      }
    }
    return [$valid, $requested_event, $current_status, $DOI];
  }

  /**
   * Attempts to Cast an ADO into DataCite V4 Metadata.
   *
   * @param array $fullvalues
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param $workflow_status
   *
   * @return mixed
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  private function castADOtoDataCite(array $fullvalues, ContentEntityInterface $entity, &$workflow_status): mixed {
    // @TODO. We need to accumulate every error on casting/and also success in $workflow_status.
    // Idea is that all messaging eventually happen via activityStreams but are are passed around/accumulated on every step.
    $dataCiteMetadata = NULL;
    $metadata_display_uuid = $this->config->get('processor_entity_id');
    if (Uuid::isValid($metadata_display_uuid)) {
      $metadatadisplay_entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')
        ->loadByProperties(['uuid' => $metadata_display_uuid]);
      if (count($metadatadisplay_entities)) {
        /** @var \Drupal\format_strawberryfield\MetadataDisplayInterface|null $metadatadisplay_entity */
        $metadatadisplay_entity = reset($metadatadisplay_entities);
        if ($metadatadisplay_entity->get('mimetype')
            ->first()
            ->getValue()['value'] !== 'application/json') {
          $message = $this->t('Wrong Data Cite Configuration at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because template with ID @metadatadisplay_entity_id is not setup for application/json output.',
            [
              '@uuid' => $entity->uuid(),
              '@action' => 'Cast ADO to DataCite Metadata',
              '@metadatadisplay_entity_id' => $metadatadisplay_entity->id()
            ]);
          $workflow_status['errors'][] = $message;
        }
        else {
          try {
            // All this is documented in other modules. No need to re-explain.
            $context['node'] = $entity;
            $context['data'] = $fullvalues;
            $context['iiif_server'] = $this->configFactory->get('format_strawberryfield.iiif_settings')
              ->get('pub_server_url');
            $original_context = $context;
            \Drupal::moduleHandler()
              ->alter('format_strawberryfield_twigcontext', $context);
            $context = $context + $original_context;
            $cacheabledata = $this->renderer->executeInRenderContext(
              new RenderContext(),
              function() use ($context, $metadatadisplay_entity) {
                return $metadatadisplay_entity->renderNative($context);
              }
            );
            if (count($cacheabledata)) {
              $jsonstring = $cacheabledata->__toString();
              $jsondata = json_decode($jsonstring, TRUE);
              $json_error = json_last_error();
              if ($json_error != JSON_ERROR_NONE) {
                $message = $this->t('Wrong Data Cite Metadata Output at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because template with ID @metadatadisplay_entity_id did not generate proper JSON. Check your template. This is the Template %output',
                  [
                    '@uuid' => $entity->uuid(),
                    '@action' => 'Cast ADO to DataCite Metadata',
                    '@metadatadisplay_entity_id' => $metadatadisplay_entity->id(),
                    '%output' => $jsonstring,
                  ]);
                $workflow_status['errors'][] = $message;
                $this->loggerFactory->get(static::LOGGER_NAME)->error($message);
              }
              else {
                // No validation Schema validation here yet
                $dataCiteMetadata = $jsondata;
              }
            }
            else {
              $message = $this->t('Wrong Data Cite Metadata Logic while Rendering template at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because template with ID @metadatadisplay_entity_id had a logic error/twig/php exception or its output is empty. Check your template',
                [
                  '@uuid' => $entity->uuid(),
                  '@action' => 'Cast ADO to DataCite Metadata',
                  '@metadatadisplay_entity_id' => $metadatadisplay_entity->id(),
                ]);
              $workflow_status['errors'][] = $message;
              $this->loggerFactory->get(static::LOGGER_NAME)->error($message);
            }
          }
          catch (\Exception $exception) {
            $message = $this->t('Wrong Data Cite Metadata Logic while Rendering template at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because template with ID @metadatadisplay_entity_id threw a logic error/twig/php exception. Check your template. This is the error message @error.',
              [
                '@uuid' => $entity->uuid(),
                '@action' => 'Cast ADO to DataCite Metadata',
                '@metadatadisplay_entity_id' => $metadatadisplay_entity->id(),
                '@error' => $exception->getMessage(),
              ]);
            $workflow_status['errors'][] = $message;
            $this->loggerFactory->get(static::LOGGER_NAME)->error($message);
          }
        }
      }
      else {
        $message = $this->t('Wrong Data Cite Configuration at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because configured template is missing. Edit your DataCite configuration and setup an existing DataCite Schema template.',
          [
            '@uuid' => $entity->uuid(),
            '@action' => 'Cast ADO to DataCite Metadata',
          ]);
        $workflow_status['errors'][] = $message;
      }
    }
    else {
      $message = $this->t('Wrong Data Cite Configuration at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because you have a  template configuration issue. Edit your DataCite configuration and setup an existing DataCite Schema template.',
        [
          '@uuid' => $entity->uuid(),
          '@action' => 'Cast ADO to DataCite Metadata',
        ]);
      $workflow_status['errors'][] = $message;
    }
    return $dataCiteMetadata;
  }

  public function validateDataCiteMetadata($jsondata): bool {
    $modulePath = \Drupal::service('module_handler')->getModule('fragaria')->getPath();
    $schema_path = $modulePath . "/json-schema/datacite-4.6-schema.json";
    $schema = file_get_contents($schema_path);
    try {
      if (!StrawberryfieldJsonHelper::isValidJsonSchema($jsondata, $schema)) {
        return FALSE;
      }
    }
    catch (\Exception $exception) {
      // JSON schema validation failed. Log?
      return FALSE;
    }
    return TRUE;
  }
}