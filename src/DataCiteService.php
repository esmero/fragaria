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
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Render\RendererInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

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
    $this->api_url = $this->
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
    $status = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0] . trim($doi);
      $response = $this->httpClient->request('GET', $api_url, [
        'headers' => [
          'accept' => 'application/vnd.api+json',
          'authorization' => 'Basic '. base64_encode($api_url_and_credentials[1].':'.$api_url_and_credentials[2])
        ],
      ]);
      $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
      if ($sucessfull) {
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents()) : [];
        $status = TRUE;
      }
      else {
        $status = FALSE;
      }
    }
    return [$status, $response_encoded];
  }

  /**
   * @param array $data
   * @param string|null $event
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function requestDOI(array $data, string|null $event) {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $status = FALSE;
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
      if ($sucessfull) {
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents()) : [];
        $status = TRUE;
      }
      else {
        $status = FALSE;
      }
    }
    return [$status, $response_encoded];
  }


  /**
   * @param array $data
   * @param string|null $event
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function updateDOI(array $data, string|null $event, string $doi) {
    $api_url_and_credentials = $this->getAPIUrlAndCredentials();
    $response_encoded = [];
    $status = FALSE;
    if ($api_url_and_credentials[1] && $api_url_and_credentials[2]) {
      $api_url = $api_url_and_credentials[0];
      $response = $this->httpClient->request('PUT', $api_url, [
        'body' => json_encode($data),
        'headers' => [
          'accept' => 'application/vnd.api+json',
          'authorization' => 'Basic '. base64_encode($api_url_and_credentials[1].':'.$api_url_and_credentials[2]),
          'content-type' => 'application/json',
        ],
      ]);
      $sucessfull = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
      if ($sucessfull) {
        $response_encoded = $sucessfull ? json_decode($response->getBody()
          ->getContents()) : [];
        $status = TRUE;
      }
      else {
        $status = FALSE;
      }
    }
    return [$status, $response_encoded];
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
   * @return void
   */
  public function evaluateWorkflow(ContentEntityInterface $entity, array $fullvalues, array|null $previous_data_cite_value) {
    // Just in case.
    if ($this->isActive()) {
      // What we need.
      // A) Do we have ['ap:tasks']['ap:fragaria']['datacite'] ?
      // IF so, is it valid?
      // B) Do we have previous to save ['ap:tasks']['ap:fragaria']['datacite'] ?
      // IF so, is it valid?
      // C) Evaluate if old version (if any) is compatible with new version.
      //   1) If incompatible: If old version IS valid, restore it but do not keep running anything. Just log.
      //   1) If compatible: Run the workflow/move the states/ Update the entity.
      // If data needs to be updated re-set $fullvalues and set the field again.
      $datacite_trigger = $fullvalues['ap:tasks']['ap:fragaria']['datacite'] ?? NULL;
      $workflow_status = [];
      $entity_status = TRUE;
      if ($entity->getEntityType()->isRevisionable() && !$entity->isLatestRevision()) {
        $entity_status = FALSE;
      }
      $entity_status =  $entity_status && $entity->isPublished();
      // Before calling anything
      if ($datacite_trigger == NULL && $previous_data_cite_value == NULL) {
        // Nothing to do here, nobody is requesting anything.
        return;
      }
      // Now validate both. We are going to use a decision matrix to decide how to proceed.
      // Super important. The only reason "event" from a previous version might be stored and passed around is IF
      // the status of that event was "error". We don't preserve the Last Event request, only the final status.
      // List of actions
      $generate_metadata = FALSE;
      $calls = [];

      $ap_task_passed_array = $this->validateApTask($datacite_trigger);
      $previous_ap_task_passed_array = $this->validateApTask($previous_data_cite_value);
      // If both states are valid. Now check possible transitions
      if ($ap_task_passed_array[0] == $previous_ap_task_passed_array[0] && $ap_task_passed_array[0] == TRUE) {
        // Most simple ones. No previous data
        // First we check if we have no DOI (previous or new)
        if ($ap_task_passed_array[3] == NULL && $ap_task_passed_array[3] == NULL) {
          if (($previous_ap_task_passed_array[2] == NULL) && $ap_task_passed_array[2] == 'draft') {
            $generate_metadata = TRUE;
            $calls[] = ['api' => 'create', 'event' => NULL];
            // call API with empty event. NO DOI passed neither
          }
          if (($previous_ap_task_passed_array[2] == NULL) && $ap_task_passed_array[2] == 'register') {
            $generate_metadata = TRUE;
            $calls[] = ['api' => 'create', 'event' => NULL];
            $calls[] = ['api' => 'update', 'event' => 'register', 'doi' => NULL];
            // This requires two calls. First create a Draft. Once Drafted. Request a status update to registered.
          }
          if (($previous_ap_task_passed_array[2] == NULL) && $ap_task_passed_array[2] == 'publish') {
            $generate_metadata = TRUE;
            $calls[] = ['api' => 'create', 'event' => 'publish'];
            // call API with publish event.
          }
        }
        // Now check if the new data holds a DOI
        if ($ap_task_passed_array[3] !== NULL) {
          // If we have an ADO, the workflow combo restrictions come in place.
          // Deal first with the most complex scenario. The user is providing a MANUAL DOI, and we have no previous history of it.
          if ($previous_ap_task_passed_array[3] == NULL && $entity_status) {
            $calls[] = ['api' => 'info', 'doi' => $ap_task_passed_array[3]];
            // Means we need to call the API and figure out if we have control over it, and IF we can execute the desired state.
          }
          if ($previous_ap_task_passed_array[3] !== NULL && $ap_task_passed_array[3] != $previous_ap_task_passed_array[3]) {
            // This is wrong. One DOI per ADO. If the user is trying to connect a new one to this, but our state says we already have one.
            // Means we need to read from the remote the original one, and decide based on that.
            $calls[] = ['api' => 'info', 'doi' => $previous_ap_task_passed_array[3]];
            $calls[] = ['api' => 'info', 'doi' => $ap_task_passed_array[3]];
            // IF the old one is DRAFT. We can delete it, and generate the new one. IF not, then we need to make the OLD one the new $DOI;
            // If the old is not under our control (anymore) or gone, we can use the NEW one and move from there.
          }
          // Finally the expected/simpler one when users don't mess with task data. Previous and New have the same DOI.
          if ($previous_ap_task_passed_array[3] !== NULL && $ap_task_passed_array[3] == $previous_ap_task_passed_array[3]) {
           // Now we can finally evaluate if the event can be run
            if ($ap_task_passed_array[2] == "draft" && $previous_ap_task_passed_array[1] == "draft") {
              // Nothing to do other than Updating metadata.
              $generate_metadata = TRUE;
              $calls[] = ['api' => 'update', 'event' => 'draft', 'doi' => $ap_task_passed_array[3]];
            }
            if ($ap_task_passed_array[2] == "register" && $previous_ap_task_passed_array[1] == "draft" && $entity_status) {
              // This requires an UPDATE status call.
              $generate_metadata = TRUE;
              $calls[] = ['api' => 'update', 'event' => 'register', 'doi' => $ap_task_passed_array[3]];
            }
            if ($ap_task_passed_array[2] == "publish" && $previous_ap_task_passed_array[1] == "draft" && $entity_status) {
              $generate_metadata = TRUE;
              $calls[] = ['api' => 'update', 'event' => 'publish', 'doi' => $ap_task_passed_array[3]];
              // This requires an UPDATE status call.
            }
            if ($ap_task_passed_array[2] == "publish" && $previous_ap_task_passed_array[1] == "registered" && $entity_status) {
              $generate_metadata = TRUE;
              $calls[] = ['api' => 'update', 'event' => 'publish', 'doi' => $ap_task_passed_array[3]];
              // This requires an UPDATE status call.
            }
            if ($ap_task_passed_array[2] == "register" && $previous_ap_task_passed_array[1] == "findable" && $entity_status) {
              $generate_metadata = TRUE;
              $calls[] = ['api' => 'update', 'event' => 'hide', 'doi' => $ap_task_passed_array[3]];
              // This requires an UPDATE status call to "hide" it.
            }
            if ($ap_task_passed_array[2] == "delete" && $previous_ap_task_passed_array[1] !== "draft") {
              // This is an error. And in that case we bail out.
            }
            if ($ap_task_passed_array[2] == "delete" && $previous_ap_task_passed_array[1] === "draft") {
              $calls[] = ['api' => 'delete', 'doi' => $ap_task_passed_array[3]];
            }
          }
        }
        if ($ap_task_passed_array[3] === NULL && $previous_ap_task_passed_array[3] !== NULL) {
          // the user might have manipulated the updated array. Wrong. But we had one before.
          // Restore the old one. There is ONLY one situation here that requires us to act differently.
          // IF the previous status was 'error'.
        }
   }



      // A new datacite_trigger being NULL is valid if we e.g. generated a Draft, and we want to delete it now.
      // But only IF there is a previous valid (with a DOI present) version in the pre-save.
      // Also, unpublished records can only have Drafts. Ok?
      // We don't make the transition automatically to Registered

    }
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
        $valid = FALSE;
      }
    }
    return [$valid, $requested_event, $current_status, $DOI];
  }

  public function validateApTaskStateChange(array|null $datacite_previous_metadata, array|null $datacite_metadata):bool {
    $valid = FALSE;

    return $valid;
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
    if (!StrawberryfieldJsonHelper::isValidJsonSchema($jsondata ,$schema)) {
      return FALSE;
    }
    return TRUE;
  }
}