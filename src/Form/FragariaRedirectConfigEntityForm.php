<?php

namespace Drupal\fragaria\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;

/**
 * Form handler for Fragaria Redirect config entity add and edit.
 */
class FragariaRedirectConfigEntityForm extends EntityForm {

  /**
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  private StrawberryfieldUtilityService $strawberryfieldUtility;

  /**
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  private PathValidatorInterface $pathValidator;

  /**
   * @var \Drupal\Core\Routing\RequestContext
   */
  private RequestContext $requestContext;

  /**
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  private AliasManagerInterface $aliasManager;

  /**
   * FragariaRedirectConfigEntityForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    PathValidatorInterface $path_validator,
    RequestContext $request_context,
    AliasManagerInterface $alias_manager
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->pathValidator = $path_validator;
    $this->requestContext = $request_context;
    $this->aliasManager = $alias_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('strawberryfield.utility'),
      $container->get('path.validator'),
      $container->get('router.request_context'),
      $container->get('path_alias.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /* @var \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $fragariaredirect_config */
    $fragariaredirect_config = $this->entity;
    // Search API indexes:

    $indexes = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadMultiple();

    // Add the indexes with matching server to $indexes_by_server
    $indexes_option = [];
    foreach ($indexes as $key => $index) {
      if ($index->isServerEnabled() && $index->isValidDatasource('entity:node')) {
        $indexes_option[$key] = $index->label();
      }
    }



    if ($form_state->isRebuilding()) {
      // Idea here is: rebuilding means user submitted values,
      // So our original Config is not longer valid
      // We use what is passed around.
      $index_id = $form_state->getValue('search_api_index', NULL);

      // NO need to get 'solr_field_select' from form state
      // since its the last step and would only
      // trigger a rebuild if index or server changes.
    }
    else {
      $index_id = !$fragariaredirect_config->isNew() ? $fragariaredirect_config->getSearchApiIndex() : NULL;
    }

    $field_options = [];
    if ($index_id && isset($indexes[$index_id])) {
      foreach ($this->strawberryfieldUtility->getStrawberryfieldSolrFields($indexes[$index_id]) as $key => $field) {
          $field_options[$key] = $field['label'];
        }
    }
    $prefixes = !$fragariaredirect_config->isNew() ? $fragariaredirect_config->getPathPrefixes() : NULL;
    $prefixes = is_array($prefixes) ? implode(PHP_EOL, $prefixes ?? '') : $prefixes;
    $form = [
      'label' => [
        '#id' => 'label',
        '#type' => 'textfield',
        '#title' => $this->t('A label for this Fragaria Redirect Entity.'),
        '#default_value' => $fragariaredirect_config->label(),
        '#required' => TRUE,
      ],
      'id' => [
        '#type' => 'machine_name',
        '#default_value' => $fragariaredirect_config->id(),
        '#machine_name' => [
          'label' => '<br/>' . $this->t('Machine name.'),
          'exists' => [$this, 'exist'],
          'source' => ['label'],
        ],
        '#disabled' => !$fragariaredirect_config->isNew(),
        '#description' => $this->t('Unique Machine name for this Fragaria Redirect Entity.'),
      ],
      'do_replacement' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable this if you removed your do/{uuid} path aliases but still (or finally) believe having REAL PURLs is important.'),
        '#description' => $this->t('This will disable suffixes, search api field matching and the Variable part will become the UUID of the node. You still need to define at least one PREFIX'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->isDoReplacement() : FALSE,
      ],
      'path_prefixes' => [
        '#type' => 'textarea',
        '#title' => $this->t('The Prefixes (that immediately follow after your domain name) for the Redirect Route.'),
        '#description' => $this->t('Enter one per line. Danger: using built in route prefixes (e.g node, admin, ajax) might break your site. Be careful!'),
        '#required' => TRUE,
        '#default_value' => $prefixes,
      ],
      'path_suffixes_element' => [
        '#type' => 'textarea',
        '#title' => $this->t('The Suffixes (that follow the prefix + the variable part) for the Redirect Route.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode(PHP_EOL, $fragariaredirect_config->getPathSuffixes()): NULL,
        '#description' => $this->t('Enter one by line. This configuration option is not required.'),
      ],
      'variable_path_suffix' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Instead of fixed Prefixes add a single {catch_all} variable suffix at the end'),
        '#required' => FALSE,
        '#return_value' => TRUE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getVariablePathSuffix(): FALSE,
        '#description' => $this->t('This disables any entered static suffixes.'),
      ],
      'search_api_index' => [
        '#type' => 'select',
        "#empty_value" => NULL,
        '#empty_option' => '- Select Solr Index -',
        '#options' => $indexes_option,
        '#title' => $this->t('The Search API Index where the Field that will be matched against the variable part of the route exists.'),
        '#required' => TRUE,
        '#limit_validation_errors' => [['search_api_index']],
        '#ajax' => [
          'callback' => '::onIndexSelect',
          'disable-refocus' => FALSE,
          'event' => 'change',
          'wrapper' => 'search-api-field-select',
        ],
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getSearchApiIndex() : NULL,
      ],
      'search_api_field' => [
        '#prefix' => '<div id="search-api-field-select">',
        '#suffix' => '</div>',
        '#type' => 'select',
        '#options' => $field_options,
        '#title' => $this->t('The Search API Field that will be matched against the variable part of the route.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getSearchApiField(): NULL,
      ],
      'search_api_field_value_prefixes_element' => [
        '#type' => 'textarea',
        '#title' => $this->t('Add static prefixes for to the variable part/argument of the path just before matching against the index.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode(PHP_EOL, $fragariaredirect_config->getSearchApiFieldValuePrefixes()): NULL,
        '#description' => $this->t('Not part of the PATH. Enter one by line. This is useful when the variable part of the ROUTE does not match 1:1 the actual indexed data. e.g the route is /oldrepo/1 and the indexed value is "namespace:1". In that case add "namespace:" here.'),
      ],
      'search_api_field_value_suffixes_element' => [
        '#type' => 'textarea',
        '#title' => $this->t('Add static suffixes for to the variable part/argument of the path just before matching against the index.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode(PHP_EOL, $fragariaredirect_config->getSearchApiFieldValueSuffixes()): NULL,
        '#description' => $this->t('Not part of the PATH. Enter one by line. This is useful when the variable part of the ROUTE does not match 1:1 the actual indexed data. e.g the route is /oldrepo/namespace:1 and the indexed value is "namespace:1-page". In that case add "-page" here.'),
      ],
      'segments_in_pattern' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('What parts of the matched URL should be sent to the search API to match against the field'),
        '#description' => $this->t('Check all that apply. At least one needs to be enabled, by default the "variable" part is enabled and we recommend to keep it so.'),
        '#options' => [
          'prefixes' => 'static prefix(es)',
          'variable' => 'variable (including variable suffix(es) if any)',
          'suffixes' => 'static or catch all suffix(es)',
        ],
        '#required' => TRUE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getSegmentsInPattern(): ['variable'],
      ],
      'redirect_http_code' => [
        '#type' => 'select',
        '#options' => [
          '301' => $this->t('Permanent Redirect'),
          '303' => $this->t('Temporary Redirect (forced GET)'),
        ],
        '#title' => $this->t('Type of HTTP redirect to perform.'),
        '#required' => TRUE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getRedirectHttpCode(): NULL,
      ],
      'custom_404' => [
        '#type' => 'textfield',
        '#title' => $this->t('A relative path to be used on not found/404 page.'),
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getCustom404(): NULL,
        '#required' => FALSE,
        '#field_prefix' => $this->requestContext->getCompleteBaseUrl(),
      ],
      'active' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Is this Fragaria Redirect Route active?'),
        '#return_value' => TRUE,
        '#default_value' => ($fragariaredirect_config->isNew()) ? TRUE : $fragariaredirect_config->isActive()
      ]
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    //Validate Custom 404 page.
    if (!$form_state->isValueEmpty('custom_404')) {
      $form_state->setValueForElement($form['custom_404'], $this->aliasManager->getPathByAlias($form_state->getValue('custom_404')));
    }
    if (($value = $form_state->getValue('custom_404')) && $value[0] !== '/') {
      $form_state->setErrorByName('custom_404', $this->t("The path '%path' has to start with a slash.", ['%path' => $form_state->getValue('custom_404')]));
    }
    if (!$form_state->isValueEmpty('custom_404') && !$this->pathValidator->isValid($form_state->getValue('custom_404'))) {
      $form_state->setErrorByName('custom_404', $this->t("Either the path '%path' is invalid or you do not have access to it.", ['%path' => $form_state->getValue('custom_404')]));
    }
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove button and internal Form API values from submitted values.
    $form_state->cleanValues();
    $suffixes = $form_state->getValue('path_suffixes_element','');
    $suffixes = array_map(function ($line) { return $line ? trim($line) : NULL;}, explode(PHP_EOL, $suffixes));
    $suffixes = array_filter($suffixes);

    $prefixes = $form_state->getValue('path_prefixes','');
    $prefixes = array_map(function ($line) { return $line ? trim($line) : NULL;}, explode(PHP_EOL, $prefixes));
    $prefixes = array_filter($prefixes);


    $search_api_field_value_prefixes = $form_state->getValue('search_api_field_value_prefixes_element','');
    $search_api_field_value_prefixes = array_map(function ($line) { return $line ? trim($line) : NULL;}, explode(PHP_EOL, $search_api_field_value_prefixes));
    $search_api_field_value_prefixes = array_filter($search_api_field_value_prefixes);

    $search_api_field_value_suffixes = $form_state->getValue('search_api_field_value_suffixes_element','');
    $search_api_field_value_suffixes = array_map(function ($line) { return $line ? trim($line) : NULL;}, explode(PHP_EOL, $search_api_field_value_suffixes));
    $search_api_field_value_suffixes = array_filter($search_api_field_value_suffixes);
    if ($form_state->getValue('do_replacement')) {
      $search_api_field_value_suffixes = [];
      $search_api_field_value_prefixes = [];
      $suffixes = [];
    }

    $this->entity = $this->buildEntity($form, $form_state);
    $this->entity->setPathSuffixes($prefixes);
    $this->entity->setDoReplacement($form_state->getValue('do_replacement') ? TRUE : FALSE);
    $this->entity->setPathSuffixes(is_array($suffixes) ? $suffixes : []);
    $this->entity->setSearchApiFieldValueSuffixes(is_array($search_api_field_value_suffixes) ? $search_api_field_value_suffixes : []);
    $this->entity->setSearchApiFieldValuePrefixes(is_array($search_api_field_value_prefixes) ? $search_api_field_value_prefixes : []);

  }


  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $fragariaredirect_config = $this->entity;
    try {
      $status = $fragariaredirect_config->save();
    }
    catch (\Exception $e) {
      $this->messenger()->addMessage(
        $this->t(
          'The %label Example could not be saved, exception @e.',
          [
            '%label' => $fragariaredirect_config->label(),
            '@e' => $e->getMessage()
          ]
        ),
        MessengerInterface::TYPE_ERROR
      );
      return;
    }

    if ($status) {
      $this->messenger()->addMessage(
        $this->t(
          'Saved the %label Metadata exposure endpoint.',
          [
            '%label' => $fragariaredirect_config->label(),
          ]
        )
      );
    }
    else {
      $this->messenger()->addMessage(
        $this->t(
          'The %label Example was not saved.',
          [
            '%label' => $fragariaredirect_config->label(),
          ]
        ),
        MessengerInterface::TYPE_ERROR
      );
    }
    $form_state->setRedirect('entity.fragariaredirect_entity.collection');
  }

  /**
   * Helper function to check whether an configuration entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('fragariaredirect_entity')
      ->getQuery()
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }

  /**
   * AJAX callback function when user selects Index
   *
   * Updates Solr Field dropdown
   */
  public function onIndexSelect(array &$form, FormStateInterface $form_state) {
    return $form['search_api_field'];
  }
}
