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
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Drupal\Core\Config\ImmutableConfig;

class DataCiteService {

  use StringTranslationTrait;
  use MessengerTrait;
  use StringTranslationTrait;

  private const LOGGER_NAME = 'fragaria_datacite';

  private const LOGGER_NAME_PEPPERMINT = 'fragaria_datacite_peppermint';

  public const DATACITE_DATA_WRAPPER = [
    'data' => [
      'type' => 'dois',
      'attributes' => []
    ]
  ];

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
  public const DATACITE_FRAGARIA_VALID_EVENTS = ['draft','register','publish', 'delete'];
  public const DATACITE_FRAGARIA_VALID_STATUSES = ['draft','registered','findable', 'error', NULL];

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
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory,  AccountInterface $currentUser, LoggerChannelFactoryInterface $loggerFactory, ClientInterface $httpClient, RendererInterface $renderer) {
    $this->entityTypeManager = $entityTypeManager;
    $this->config = $configFactory->get(
      'fragaria.datacite'
    );
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory;
    $this->currentUser = $currentUser;
    $this->httpClient = $httpClient;
    $this->renderer = $renderer;
    // Setup the Fragaria data cite monolog file logger
    $log = new Logger(static::LOGGER_NAME);
    $private_path = \Drupal::service('stream_wrapper_manager')->getViaUri('private://')->getDirectoryPath();
    $handler = new StreamHandler($private_path . '/fragaria/logs/datacite.log', Logger::DEBUG);
    $handler->setFormatter(new JsonFormatter());
    $log->pushHandler($handler);
    $this->loggerFactory->get(static::LOGGER_NAME)->setLoggers([[$log]]);

    $log_good_things = new Logger(static::LOGGER_NAME_PEPPERMINT);
    $private_path = \Drupal::service('stream_wrapper_manager')->getViaUri('private://')->getDirectoryPath();
    $handler_mint = new StreamHandler($private_path . '/fragaria/logs/datacite_peppermint.log', Logger::DEBUG);
    $handler_mint->setFormatter(new JsonFormatter());
    $log_good_things->pushHandler($handler_mint);
    $this->loggerFactory->get(static::LOGGER_NAME_PEPPERMINT)->setLoggers([[$log_good_things]]);
  }


  public function isActive(): bool {
    return (bool) $this->config->get('active') ?? FALSE;
  }

  public function ignoreEntityStatus(): bool {
    return (bool) $this->config->get('ignore_entity_status') ?? FALSE;
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
    }
    else {
      return NULL;
    }
  }


  /**
   * Returns the active URL type
   *    uuid for do/uuid
   *    canonical for the Node canonical.
   *
   * @return string|null
   */
  public function getUrlType(): ?string {
    // @TODO. Make sure we also have password and user for both before returning
    if ($this->config->get('use_do_url')) {
      return 'uuid';
    }
    else {
      return 'canonical';
    }
  }


  /**
   * Fetches from DataCite a single DOI info,
   *
   * @param string $doi
   *
   * @return array
   */
  public function fetchDOI(string $doi): array {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $sucessfull = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      try {
        $api_url = $api_url_and_credentials[0] . trim($doi);
        $response = $this->httpClient->request('GET', $api_url, [
          'headers' => [
            'accept' => 'application/vnd.api+json',
            'authorization' => 'Basic ' . base64_encode($api_url_and_credentials[1] . ':' . $api_url_and_credentials[2])
          ],
        ]);
        $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents(), TRUE) : [];
      }
      catch(GuzzleException $guzzleException) {
        $this->loggerFactory->get(static::LOGGER_NAME)->error($this->t('DOI INFO REST API Call for DOI @doi failed with error @error', [
          '@error' => $guzzleException->getResponse()->getBody()->getContents(),
          '@doi' => $doi
        ]));
        return [FALSE, []];
      }
    }
    return [$sucessfull, $response_encoded];
  }

  /**
   * @param array $data
   *
   * @return array
   */
  public function requestDOI(array $data):array {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $sucessfull = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      try {
        $api_url = $api_url_and_credentials[0];
        $response = $this->httpClient->request('POST', $api_url, [
          'body' => json_encode($data),
          'headers' => [
            'accept' => 'application/vnd.api+json',
            'authorization' => 'Basic ' . base64_encode($api_url_and_credentials[1] . ':' . $api_url_and_credentials[2]),
            'content-type' => 'application/json',
          ],
        ]);
        $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents(), TRUE) : [];
      }
      catch(GuzzleException $guzzleException) {
        $this->loggerFactory->get(static::LOGGER_NAME)->error($this->t('DOI Create REST API Call failed with error @error', [
          '@error' => $guzzleException->getResponse()->getBody()->getContents(),
        ]));
        return [FALSE, []];
      }
    }
    return [$sucessfull, $response_encoded];
  }

  /**
   * @param array $data
   * @param string $doi
   *
   * @return array
   */
  public function updateDOI(array $data, string $doi):array {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $sucessfull = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0].trim($doi);
      try {
        $response = $this->httpClient->request('PUT', $api_url, [
          'body' => json_encode($data),
          'headers' => [
            'accept' => 'application/vnd.api+json',
            'authorization' => 'Basic ' . base64_encode($api_url_and_credentials[1] . ':' . $api_url_and_credentials[2]),
            'content-type' => 'application/json',
          ],
        ]);
        $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents(), TRUE) : [];
      }
      catch(GuzzleException $guzzleException) {
        $this->loggerFactory->get(static::LOGGER_NAME)->error($this->t('DOI Update REST API Call for DOI @doi failed with error @error', [
          '@error' => $guzzleException->getResponse()->getBody()->getContents(),
          '@doi' => $doi
        ]));
        return [FALSE, []];
      }
    }
    return [$sucessfull, $response_encoded];
  }


  /**
   * Deletes (a draft) DOI from DataCite.
   *
   * @param string $doi
   *
   * @return boolean
   */
  public function deleteDOI(string $doi): bool {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $status = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      try {
        $api_url = $api_url_and_credentials[0] . trim($doi);
        $response = $this->httpClient->request('DELETE', $api_url, [
          'headers' => [
            'authorization' => 'Basic ' . base64_encode($api_url_and_credentials[1] . ':' . $api_url_and_credentials[2])
          ],
        ]);
        $status = $response->getStatusCode() == 204;
      }
      catch(GuzzleException $guzzleException) {
        $this->loggerFactory->get(static::LOGGER_NAME)->error($this->t('DOI DELETE REST API Call for DOI @doi failed with error @error', [
          '@error' => $guzzleException->getResponse()->getBody()->getContents(),
          '@doi' => $doi
        ]));
        return FALSE;
      }
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
   * @return array|null
   *    Array if we need to update the original data, NULL if no update is needed.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function evaluateWorkflow(ContentEntityInterface $entity, array $fullvalues, array|null $previous_data_cite_value, array &$workflow_status ): ?array {
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
      // Clean the trigger and the previous value if they are arrays.
      if (is_array($datacite_trigger)) {
        $datacite_trigger = array_filter($datacite_trigger);
      }
      if (is_array($previous_data_cite_value)) {
        $previous_data_cite_value = array_filter($previous_data_cite_value);
      }

      $workflow_status = [];
      $entity_status = TRUE;
      if ($entity->getEntityType()->isRevisionable() && !$entity->isLatestRevision()) {
        $entity_status = FALSE;
      }
      $entity_status =  $entity_status && $entity->isPublished();
      // New. Allows published and registered to be called even if entity NOT yet published.
      // But generates a LOG for the user.
      $ignore_entity_status = $this->ignoreEntityStatus();
      // Before calling anything
      if ($datacite_trigger == NULL && $previous_data_cite_value == NULL) {
        // Nothing to do here, nobody is requesting anything or APIs don't match
        // But we won't act on a non-active API.
        return NULL;
      }
      // There is NO ID for a new Entity. And toURL will fail.
      if ($this->config->get('use_do_url') || $entity->isNew()) {
        // If so, we will have to use a post save subscriber to update the URL ONCE saved.
        $ado_url = Url::fromRoute('<front>')->setAbsolute();
        $ado_url = $ado_url->toString()."/do/" . $entity->uuid();
      }
      else {
        $ado_url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }

      $calls = [];

      // Now validate both. We are going to use a decision matrix to decide how to proceed.
      // Super important. The only reason "event" from a previous version might be stored and passed around is IF
      // the status of that event was "error". We don't preserve the Last Event request, only the final status.
      $ap_task_passed_array = $this->validateApTask($datacite_trigger);
      $previous_ap_task_passed_array = $this->validateApTask($previous_data_cite_value);
      // In case nothing evaluates. Leave the previous mess
      $ap_task_parsed_data = $previous_ap_task_passed_array ?? [];

      // @TODO. Make this very long chunk of nested if/else reusable method and simpler.
      // If both states are valid. Now check possible transitions

      // Edge case. A Revision/error provides invalid previous_ap_task_passed array
      // But the current one is valid and has a DOI. If so, we can not relay on the previous state of the DOI
      // But we can force a remote fetch and make it valid afterward.
      // For this we need to make sure that is evaluated before
      if (($ap_task_passed_array['valid']  && !$previous_ap_task_passed_array['valid']) && ($ap_task_passed_array['doi'] ?? FALSE)) {
        // here we will use the DataCite API to fetch the Current Status since we can't depend on previous statuses
        $check_existing_doi = $this->fetchDOI($ap_task_passed_array['doi']);
        $failed_remote = TRUE;
        if ($check_existing_doi[0] && ($check_existing_doi[1]['data']['attributes'] ?? FALSE)) {
          $doi = $check_existing_doi[1]['data']['attributes']['doi'] ?? NULL;
          $doi_status = $check_existing_doi[1]['data']['attributes']['state'] ?? NULL;
          // To make this work, we will set the Old data to the known data from the API.
          if ($doi_status && $doi) {
            $previous_ap_task_passed_array['status'] = $doi_status;
            $previous_ap_task_passed_array['doi'] = $doi;
            $previous_ap_task_passed_array['valid'] = TRUE;
            $failed_remote = FALSE;
          }
        }

        if ($failed_remote) {
          $message = $this->t('Your previously saved DOI status was wrong and the DOI @doi you provided could not be verified via a remote API call for ADO with UUID @uuid. Check your ap:task key values.',
            [
              '@uuid' => $entity->uuid(),
              '@doi' =>$previous_ap_task_passed_array['doi'],
            ]);
          $workflow_status['error'][] = $message;
        }
        // Remove the data.
        $ap_task_parsed_data = [];
      }
      // An status == null without a DOI is valid, and could be result of a failed op in the past.
      // And we should not report on it.
      if (($ap_task_passed_array['valid'] == $previous_ap_task_passed_array['valid']) && $ap_task_passed_array['valid']) {
        // Pre-set this. Worst case scenario we will return the same data.
        $ap_task_parsed_data = $ap_task_passed_array;
        // Most simple ones. No previous data
        // NO DOI (previous or new)
        if ($ap_task_passed_array['doi'] == NULL && $previous_ap_task_passed_array['doi'] == NULL) {
          // If we don't have a DOI on the previous data then whatever status is there is irrelevant
          if ($ap_task_passed_array['event'] == 'draft') {
            $calls[] = ['api' => 'create', 'event' => NULL];
            // call API with empty event. NO DOI passed neither
          }
          elseif ($ap_task_passed_array['event'] == 'register' && ($entity_status || $ignore_entity_status)) {
            $calls[] = ['api' => 'create', 'event' => NULL];
            $calls[] = ['api' => 'update', 'event' => 'register', 'doi' => NULL];
            // This requires two calls. First create a Draft. Once Drafted. Request a status update to registered.
          }
          elseif ($ap_task_passed_array['event'] == 'publish' && ($entity_status || $ignore_entity_status)) {
            $calls[] = ['api' => 'create', 'event' => 'publish'];
            // call API with publish event.
          }
          else {
            $message = $this->t('Wrong Data Cite Event/ADO Status combination. We could not run your DOI workflow for ADO with UUID @uuid. Check your ap:task key values. Prestate was <em>@prestate</em>, requested state was <em>@desiredstate</em>',
              [
                '@uuid' => $entity->uuid(),
                '@prestate' => json_encode($previous_ap_task_passed_array) ?? 'Undefined',
                '@desiredstate' => json_encode($ap_task_passed_array) ?? 'Undefined',
              ]);
            $workflow_status['error'][] = $message;
            // IF entity status is not published we can not run Publish or register.
            // What is else under NO DOI? Wrong combo of operations?
          }
        }
        elseif ($ap_task_passed_array['doi'] == NULL && $previous_ap_task_passed_array['doi'] !== NULL) {
          // the user might have manipulated the updated array. Wrong. But we had one before.
          // Restore it.
          $ap_task_passed_array = $previous_ap_task_passed_array;
          $ap_task_parsed_data = $ap_task_passed_array;
          $message = $this->t('You already had a DOI @doi for ADO with UUID @uuid. If your DOI is a draft use the "delete" event to remove it. If under any other state, you can no longer delete it via Archipelago. Restoring old data.',
            [
              '@uuid' => $entity->uuid(),
              '@doi' => $previous_ap_task_passed_array['doi'],
            ]);
          $workflow_status['error'][] = $message;
          // Restore the old one. There might be ONLY one situation here that requires us to act differently.
          // IF the previous status was 'error'. But that should either resolve again in replaying?
        }
        // DOI IN NEW DATA
        elseif  ($ap_task_passed_array['doi'] !== NULL) {
          // If we have an ADO, the workflow combo restrictions come in place.
          // Deal first with the most complex scenario. The user is providing a MANUAL DOI, and we have no previous history of it.
          $doi = NULL;
          $doi_status = NULL;
          if ($previous_ap_task_passed_array['doi'] == NULL) {
            $check_new_doi = $this->fetchDOI($ap_task_passed_array['doi']);
            if ($check_new_doi[0] && ($check_new_doi[1]['data']['attributes'] ?? FALSE)) {
              $doi = $check_new_doi[1]['data']['attributes']['doi'] ?? $doi;
              $doi_status = $check_new_doi[1]['data']['attributes']['state'] ?? $doi_status;
              // To make this work, we will set the Old data to the known data from the API.
              $previous_ap_task_passed_array['status'] = $doi_status;
              $previous_ap_task_passed_array['doi'] = $doi;
              $ap_task_parsed_data = $previous_ap_task_passed_array;
            }
            else {
              $message = $this->t('Wrong Data Cite DOI @doi. We could not verify the DOI status via a remote API call for ADO with UUID @uuid. Check your ap:task key values.',
                [
                  '@uuid' => $entity->uuid(),
                  '@doi' =>$previous_ap_task_passed_array['doi'],
                ]);
              $workflow_status['error'][] = $message;
            }
          }

          if ($previous_ap_task_passed_array['doi'] !== NULL && $ap_task_passed_array['doi'] != $previous_ap_task_passed_array['doi']) {
            // This is wrong. One DOI per ADO. If the user is trying to connect a new one to this, but our state says we already have one.
            // Means we need to read from the remote the original one, and decide based on that.
            // check if
            $check_original_doi = $this->fetchDOI($previous_ap_task_passed_array['doi']);
            // I could check for URL, but if the user decided to use aliases and the alias changed I won't have a clue here.
            if ($check_original_doi[0] && ($check_original_doi[1]['data']['attributes'] ?? FALSE)) {
              $doi = $check_original_doi[1]['data']['attributes']['doi'] ?? $doi;
              $doi_status = $check_original_doi[1]['data']['attributes']['state'] ?? $doi_status;
              // No workflow to be done. But we should restore the old data though.
              // But no Event?
              $previous_ap_task_passed_array['status'] = $doi_status;
              $previous_ap_task_passed_array['doi'] = $doi;
              $ap_task_parsed_data = $previous_ap_task_passed_array;
            }
            else {
              $message = $this->t('Wrong Data Cite DOI @doi. We could not verify the status via a remote API call for ADO with UUID @uuid. Check your ap:task key values.',
                [
                  '@uuid' => $entity->uuid(),
                  '@doi' => $previous_ap_task_passed_array['doi'],
                ]);
              $workflow_status['error'][] = $message;
              // @LOG First Fetch failure
              // Only if the original one does not exist. DOIs are expensive.
              // In this case we can actually keep evaluating.
              $check_new_doi = $this->fetchDOI($ap_task_passed_array['doi']);
              if ($check_new_doi[0] && ($check_new_doi[1]['data']['attributes'] ?? FALSE)) {
                $doi = $check_new_doi[1]['data']['attributes']['doi'] ?? $doi;
                $doi_status = $check_new_doi[1]['data']['attributes']['state'] ?? $doi_status;
                // To make this work, we will set the Old data to the known data from the API.
                $previous_ap_task_passed_array['status'] = $doi_status;
                $previous_ap_task_passed_array['doi'] = $doi;
                $ap_task_parsed_data = $previous_ap_task_passed_array;
              }
              else {
                $message = $this->t('Wrong Data Cite DOI @doi. We could not verify the status via a remote API call for ADO with UUID @uuid. Check your ap:task key values.',
                  [
                    '@uuid' => $entity->uuid(),
                    '@doi' => $ap_task_passed_array['doi'],
                  ]);
                $workflow_status['error'][] = $message;
              }
            }
          }

          // Finally the expected/simpler one when users don't mess with task data. Previous and New have the same DOI.
          if ($previous_ap_task_passed_array['doi'] !== NULL && $ap_task_passed_array['doi'] == $previous_ap_task_passed_array['doi']) {
            // Now we can finally evaluate if the event can be run
            if ($ap_task_passed_array['event'] == NULL) {
              // Nothing to do other than Updating metadata.
              $calls[] = ['api' => 'update', 'event' => NULL, 'doi' => $ap_task_passed_array['doi']];
            }
            elseif ($ap_task_passed_array['event'] == "draft" && $previous_ap_task_passed_array['status'] == "draft") {
              // Nothing to do other than Updating metadata. No need to pass an event here.
              $calls[] = ['api' => 'update', 'event' => NULL, 'doi' => $ap_task_passed_array['doi']];
            }
            elseif ($ap_task_passed_array['event'] == "register" && $previous_ap_task_passed_array['status'] == "draft" && ($entity_status || $ignore_entity_status)) {
              // This requires an UPDATE status call.
              $calls[] = ['api' => 'update', 'event' => 'register', 'doi' => $ap_task_passed_array['doi']];
            }
            elseif ($ap_task_passed_array['event'] == "publish" && $previous_ap_task_passed_array['status'] == "draft" && ($entity_status || $ignore_entity_status)) {

              $calls[] = ['api' => 'update', 'event' => 'publish', 'doi' => $ap_task_passed_array['doi']];
              // This requires an UPDATE status call.
            }
            elseif ($ap_task_passed_array['event'] == "publish" && $previous_ap_task_passed_array['status'] == "registered" && ($entity_status || $ignore_entity_status)) {

              $calls[] = ['api' => 'update', 'event' => 'publish', 'doi' => $ap_task_passed_array['doi']];
              // This requires an UPDATE status call.
            }
            elseif ($ap_task_passed_array['event'] == "register" && $previous_ap_task_passed_array['status'] == "findable" && ($entity_status || $ignore_entity_status)) {

              $calls[] = ['api' => 'update', 'event' => 'hide', 'doi' => $ap_task_passed_array['doi']];
              // This requires an UPDATE status call to "hide" it.
            }
            elseif ($ap_task_passed_array['event'] == "delete" && $previous_ap_task_passed_array['status'] !== "draft") {
              $message = $this->t('Wrong Data Cite Event "Delete" request for DOI @doi for ADO with UUID @uuid. You can not delete a non draft DOI.',
                [
                  '@uuid' => $entity->uuid(),
                  '@doi' => $ap_task_passed_array['doi'],
                ]);
              $workflow_status['error'][] = $message;
            }
            elseif ($ap_task_passed_array['event'] == "delete" && $previous_ap_task_passed_array['status'] === "draft") {
              $calls[] = ['api' => 'delete', 'doi' => $ap_task_passed_array['doi']];
            }
            else {
              $message = $this->t('Wrong Data Cite Event Transition Combination for DOI @doi for ADO with UUID @uuid.',
                [
                  '@uuid' => $entity->uuid(),
                  '@doi' => $ap_task_passed_array['doi'],
                ]);
              error_log('entity status'.$entity_status);
              error_log('desired_state'.json_encode($ap_task_passed_array));
              error_log('previous_state'.json_encode($previous_ap_task_passed_array));
              $workflow_status['error'][] = $message;
              // What is else here?
              if (in_array($ap_task_passed_array['event'] ?? NULL, ["publish","register"]) && !$entity_status && !$ignore_entity_status) {
                $message = $this->t('You can not transition to @event for DOI @doi for ADO with UUID @uuid because only Published ADOs can have public DOIs.',
                  [
                    '@uuid' => $entity->uuid(),
                    '@doi' => $ap_task_passed_array['doi'],
                    '@event' => $ap_task_passed_array['event'],
                  ]);
                $workflow_status['error'][] = $message;
              }
            }
          }
        }
      }
      elseif ($previous_ap_task_passed_array['valid'] && !$ap_task_passed_array['valid']) {
        // Previous is OK, new one is not Valid. This includes a previously errored one though. So no action.
        // We restore the previous state but no data is sent to the API.
        $ap_task_parsed_data = $previous_ap_task_passed_array;
        $message = $this->t('Your API DataCite Task data is invalid but the previous one from a revision was Ok. Restoring for UUID @uuid, but no further action will be executed',
          [
            '@uuid' => $entity->uuid(),
            '@doi' =>$previous_ap_task_passed_array['doi'] ?? 'no previous DOI',
          ]);
        $workflow_status['error'][] = $message;
      }
      elseif (!$previous_ap_task_passed_array['valid'] && !$ap_task_passed_array['valid']) {
        // Both wrong. If invalid. We delete right? Yeah.
        $ap_task_parsed_data = [];
        $message = $this->t('Your API DataCite Task data is invalid. Removing it from ADO with UUID @uuid',
          [
            '@uuid' => $entity->uuid(),
            '@doi' =>$previous_ap_task_passed_array['doi'] ?? 'no previous DOI',
          ]);
        $workflow_status['error'][] = $message;
      }
      // So if the previous one is invalid and the new one is valid?
      // Should never happen but there are edge cases. e.g. the Structure was pushed into the ADO but
      // But also a revert on a revision.
      // when DataCite was not enabled/bug/etc. So it lingers around.
      $doi_prefix = $this->config->get('doi_prefix');
      if (count($calls) && $doi_prefix) {
        // Call the APIs.
        // Let's generate metadata first.
        $data_cite_metadata = $this->castADOtoDataCite($fullvalues, $entity, $workflow_status);
        if ($data_cite_metadata !== NULL) {
          $data_cite_metadata['url'] = $ado_url;
          $data_wrapper = static::DATACITE_DATA_WRAPPER;
          $data_wrapper['data']['attributes'] = $data_cite_metadata;
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
                $doi = $response[1]['data']['attributes']['doi'] ?? NULL;
                $status = $response[1]['data']['attributes']['state'] ?? NULL;
                $ap_task_parsed_data = ['valid' => TRUE, 'event' => NULL, 'status' => $status, 'doi' => $doi];
                $message = $this->t('DOI @doi Minted for ADO with UUID @uuid.',
                  [
                    '@uuid' => $entity->uuid(),
                    '@doi' => $doi,
                  ]);
                $workflow_status['info'][] = $message;
              }
              else {
                $ap_task_parsed_data['error'] = true;
                $message = $this->t('DOI Minting failed for ADO with UUID @uuid.',
                  [
                    '@uuid' => $entity->uuid(),
                  ]);
                $workflow_status['error'][] = $message;
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
                  $doi = $response[1]['data']['attributes']['doi'] ?? NULL;
                  $status = $response[1]['data']['attributes']['state'] ?? NULL;
                  $ap_task_parsed_data = ['valid' => TRUE, 'event' => NULL, 'status' => $status, 'doi' => $doi];
                  $message = $this->t('DOI @doi Metadata and Info updated for ADO with UUID @uuid successfully.',
                    [
                      '@uuid' => $entity->uuid(),
                      '@doi' => $doi,
                    ]);
                  $workflow_status['info'][] = $message;
                }
                else {
                  $ap_task_parsed_data['error'] = TRUE;
                  $message = $this->t('DOI @doi  Metadata and Info update failed for ADO with UUID @uuid.',
                    [
                      '@uuid' => $entity->uuid(),
                      '@doi' => $doi_update,
                    ]);
                  $workflow_status['error'][] = $message;
                }
              }
            }
            elseif ($call['api'] == 'delete')  {
              $doi_delete = $call['doi'] ?? $doi;
              $response = $this->deleteDOI($doi_delete);
              if ($response) {
                $ap_task_parsed_data = [];
                $message = $this->t('DOI @doi deleted for ADO with UUID @UUID.',
                  [
                    '@uuid' => $entity->uuid(),
                    '@doi' => $doi_delete,
                  ]);
                $workflow_status['info'][] = $message;
                // remove the $ap_task. Deleted and done.
              }
              else {
                $message = $this->t('DOI @doi deletion failed for ADO with UUID @UUID.',
                  [
                    '@uuid' => $entity->uuid(),
                    '@doi' => $doi_delete,
                  ]);
                $workflow_status['error'][] = $message;
              }
            }
          }
          // Give the user a late nugde about entity status, but only if we called the backend.
          // And we got a proper DOI from the backend too.
          // No need to alert the user about this if the API failed.
          if ($doi && in_array($ap_task_passed_array['event'] ?? NULL, ["publish","register"]) && !$entity_status && $ignore_entity_status) {
            $message = $this->t('You called event @event for DOI @doi, ADO with UUID @uuid on a not (yet) published ADO. We allowed it because you have "Ignore Entity Status" globally enabled in your DataCite Configuration. It is your responsability to ensure any public facing DOIs point to a published ADO.',
              [
                '@uuid' => $entity->uuid(),
                '@doi' => $doi,
                '@event' => $ap_task_passed_array['event'],
              ]);
            $workflow_status['warning'][] = $message;
          }
        }
        else {
          $message = $this->t('DOI Updated failed for ADO with UUID @UUID because of invalid Metadata Display Output. Check your Twig syntax and DataCite V4 schema conformance.',
            [
              '@uuid' => $entity->uuid(),
            ]);
          $workflow_status['error'][] = $message;
          $ap_task_parsed_data['error'] = TRUE;
        }
      }



      $datacite_metadata = $this->generateApTask($ap_task_parsed_data);
      // Log all workflow Status
      foreach (($workflow_status['error'] ?? []) as $error_message) {
        $this->loggerFactory->get(static::LOGGER_NAME)->error($error_message);
        // Also log to main Drupal logger.
        $this->loggerFactory->get('fragaria')->error($error_message);
      }
      foreach (($workflow_status['warning'] ?? []) as $warning_message) {
        $this->loggerFactory->get(static::LOGGER_NAME)->error($warning_message);
        // Also log to main Drupal logger.
        $this->loggerFactory->get('fragaria')->error($warning_message);
      }

      foreach (($workflow_status['info'] ?? []) as $info_message) {
        $this->loggerFactory->get(static::LOGGER_NAME_PEPPERMINT)->info($info_message);
      }
      return $datacite_metadata;
    }
    else {
      return NULL;
    }
    // A new datacite_trigger being NULL is valid if we e.g. generated a Draft, and we want to delete it now.
    // But only IF there is a previous valid (with a DOI present) version in the pre-save.
    // Also, unpublished records can only have Drafts. Ok?
    // We don't make the transition automatically to Registered
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
   *    The ap:tasks datacite metadata from an ADO>
   * @return array
   *     An associative array with the following values
   *       valid => Boolean: Validation of basic structure
   *       event => Requested Event. Defaults to NULL
   *       status => > Current Status: Defaults to NULL
   *       doi => DOI if found. Defaults to NULL
   */
  public function validateApTask(array|null $datacite_metadata):array {
    $valid = TRUE;
    $requested_event = NULL;
    $current_status = NULL;
    $DOI = NULL;
    // API needs to only match IF there is a 'status'. present. If new the request happens on the active one.
    // Here we assume there can be only ONE dataCite ID at the time. Not ONE per API.
    // NULL is valid. Like no data.
    if (empty($datacite_metadata)) {
      return ['valid' => TRUE, 'event'=> $requested_event, 'status' => $current_status, 'doi' => $DOI];
    }
    else {
      if (is_array($datacite_metadata) &&
        in_array(($datacite_metadata['event'] ?? NULL),static::DATACITE_FRAGARIA_VALID_EVENTS)) {
        $requested_event = $datacite_metadata['event'];
        // Here we know we have an event. The validation now is a bit deeper.
        // Status should only exist IF error/or if we have a DOI.
        // Note. Status might have been removed. Should not be the case, but we will
        // Entertain the idea that IF we do have an DOI, we can request via the REST API a status updated?
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
        // Here we demand a status/DOI combo. But if for some reason we lack
        // a status, we will request the status from the REST API.
        // Why? There are ways a previous revision (when reverting a revision) might
        // have a DOI but no status (e.g failed/pre enablign API data)
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
        else {
          if (isset($datacite_metadata['doi']) && is_string($datacite_metadata['doi'])) {
            $valid = $this->validateDOI($datacite_metadata['doi']);
            if ($valid) {
              $DOI = $datacite_metadata['doi'];
              // Now try to get a status from the API.
              $check_existing_doi = $this->fetchDOI($DOI);
              if ($check_existing_doi[0] && ($check_existing_doi[1]['data']['attributes'] ?? FALSE)) {
                $doi = $check_existing_doi[1]['data']['attributes']['doi'] ?? NULL;
                $doi_status = $check_existing_doi[1]['data']['attributes']['state'] ?? NULL;
                // To make this work, we will set the Old data to the known data from the API.
                if ($doi_status && $doi) {
                  $current_status = $doi_status;
                  $failed_remote = FALSE;
                }
                else {
                  $DOI = NULL;
                  $valid = FALSE;
                  $current_status = NULL;
                }
              }
              else {
                $DOI = NULL;
                $valid = FALSE;
                $current_status = NULL;
              }
            }
          }
          else {
            // NO DOI and empty status, or status is NULL or not in the controlled vocab list
            $current_status = NULL;
            $valid  = TRUE;
          }
        }
      }
    }
    return ['valid' => $valid, 'event' => $requested_event, 'status' => $current_status, 'doi' => $DOI];
  }


  /**
   * @param array|null $parsed_doi_data
   *    An array with the following values
   *      Boolean: Validation of basic structure
   *      Requested Event. Defaults to NULL
   *      Current Status: Defaults to NULL
   *      DOI if found. Defaults to NULL
   *
   * @return array
   */
  public function generateApTask(array|null $parsed_doi_data):array {
    $datacite_metadata = [];
    if ($parsed_doi_data == [] || !($parsed_doi_data['valid'] ?? FALSE)) {
      return $datacite_metadata;
    }
    else {
      if (($parsed_doi_data['error'] ?? FALSE) == TRUE) {
        // Only there we keep the event.
        // if there is an event at all.
        if ($parsed_doi_data['event']) {
          $datacite_metadata['event'] = $parsed_doi_data['event'];
          $datacite_metadata['status'] = $parsed_doi_data['status'];
        }
      }
      else {
        $datacite_metadata['status'] = $parsed_doi_data['status'];
      }
      if ($parsed_doi_data['doi']) {
        $datacite_metadata['doi'] = $parsed_doi_data['doi'];
      }
    }
    //add timestamp?
    return $datacite_metadata;
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
          $workflow_status['error'][] = $message;
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
                $workflow_status['error'][] = $message;
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
              $workflow_status['error'][] = $message;

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
            $workflow_status['error'][] = $message;
          }
        }
      }
      else {
        $message = $this->t('Wrong Data Cite Configuration at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because configured template is missing. Edit your DataCite configuration and setup an existing DataCite Schema template.',
          [
            '@uuid' => $entity->uuid(),
            '@action' => 'Cast ADO to DataCite Metadata',
          ]);
        $workflow_status['error'][] = $message;
      }
    }
    else {
      $message = $this->t('Wrong Data Cite Configuration at @action. We could not cast ADO with UUID @uuid to Data Cite Metadata because you have a  template configuration issue. Edit your DataCite configuration and setup an existing DataCite Schema template.',
        [
          '@uuid' => $entity->uuid(),
          '@action' => 'Cast ADO to DataCite Metadata',
        ]);
      $workflow_status['error'][] = $message;
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