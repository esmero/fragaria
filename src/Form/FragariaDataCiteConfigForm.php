<?php

namespace Drupal\fragaria\Form;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class FragariaDataCiteConfigForm.
 */
class FragariaDataCiteConfigForm extends ConfigFormBase {


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entityTypeManager) {
    $this->setConfigFactory($config_factory);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }


  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'fragaria.datacite',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'format_datacite_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fragaria.datacite');

    $template = $config->get('processor_entity_id', NULL);
    if ($template) {
      $entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $template]);
      if (!count($entities)) {
        $template = NULL;
        $this->messenger()->addWarning(
          $this->t('The Configured Template for DataCite does not longer exist. Please provide a new one'));
      }
      else {
        $entity = reset($entities);
        // Check if entity is still of type application/json. People do crazy stuff sometimes.
        $entity;
      }
    }

    $form['info'] = [
      '#markup' => $this->t(
        'DataCite Fabrica/DOI Configuration Form'
      ),
    ];

    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('DOI/DataCite REST API interaction is Active'),
      '#default_value' => (bool) $config->get(
        'active') ?? FALSE
    ];

    $form['use_do_url'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always use do/{uuid} as the URL'),
      '#description' => $this->t('If disabled the current alias (canonical URL) of the NODE will be used. When enabled , all URLs sent to DataCite will be in the form of (https|http)//:{yourdomain}/do/{uuid}. We recommend you use always use the do/uuid alias for ADOs or enable in this module the automatic workaround. '),
      '#default_value' => (bool) $config->get(
      'use_do') ?? TRUE
    ];

    $form['repository_fabrica_user'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t(
        'DataCite/Fabrica Repository User'
      ),
      '#description'   => $this->t(
        'Please provide your Institution\'s DataCite/Fabrica Repository User. Normally in the form of "xxx.xxx"'
      ),
      '#default_value' => $config->get('repository_fabrica_user') ?? '',
      '#required' => FALSE
    ];

    $form['repository_fabrica_password'] = [
      '#type'          => 'password',
      '#title'         => $this->t(
        'DataCite/Fabrica Repository Password'
      ),
      '#description'   => $this->t(
        'Please provide your Institution\'s DataCite/Fabrica Repository Password. If you have set it before, please leave empty.'
      ),
      '#default_value' => $config->get('repository_fabrica_password') ?? '',
      '#required' => FALSE
    ];

    $form['repository_fabrica_user_test'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t(
        'DataCite/Fabrica Repository Test User'
      ),
      '#description'   => $this->t(
        'Please provide your Institution\'s DataCite/Fabrica Test Repository User. Normally in the form of "xxx.xxx"'
      ),
      '#default_value' => $config->get('repository_fabrica_user_test') ?? '',
      '#required' => FALSE
    ];

    $form['repository_fabrica_password_test'] = [
      '#type'          => 'password',
      '#title'         => $this->t(
        'DataCite/Fabrica Repository Test Password'
      ),
      '#description'   => $this->t(
        'Please provide your Institution\'s DataCite/Fabrica Test Repository Password. If you have set it before please leave empty.'
      ),
      '#default_value' => $config->get('repository_fabrica_password_test') ?? '',
      '#required' => FALSE
    ];

    $form['use_test_account'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only use the test/staging DataCite API. DOIs will not be persistent/redirectable or even findable.'),
      '#default_value' => $config->get('use_test_account', FALSE) ?? FALSE,
      '#description' => $this->t('If enabled, DataCite Fabrica DOI REST API requests and metadata workflows will interact against DataCite\'s Test endpoints at @url. Enable if you are in early stages of evaluation and want to get used to the workflow.',[
        '@url' => "https://support.datacite.org/docs/testing-guide"
      ]),
    ];

    $form['doi_prefix'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t(
        'DOI prefix.'
      ),
      '#description'   => $this->t(
        'Please provide a valid/registered Prefix for DOI assignment, belonging to your active (Test or production) DataCite/Fabrica Repository account.'
      ),
      '#default_value' => $config->get('doi_prefix') ?? '',
      '#required' => TRUE
    ];

    $form['processor_entity_id'] = [
      '#type' => 'sbf_entity_autocomplete_uuid',
      '#title' => $this->t('The Metadata display Entity (Twig) to be used to generate DataCite Metadata from your ADO.'),
      '#target_type' => 'metadatadisplay_entity',
      '#selection_handler' => 'default:metadatadisplay',
      '#validate_reference' => TRUE,
      '#selection_settings' => [
        'filter' => [
          'mimetype' => 'application/json'
        ]
      ],
      '#required' => TRUE,
      '#default_value' =>  $template,
      '#maxlength' => 300,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * @inheritDoc
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    if (empty($values['repository_fabrica_user_test'])) {
      $values['repository_fabrica_password_test'] = '';
    }
    elseif (empty($values['repository_fabrica_password_test'])) {
      $form_state->unsetValue('repository_fabrica_password_test');
    }

    if (empty($values['repository_fabrica_user'])) {
      $values['repository_fabrica_password'] = '';
    }
    elseif (empty($values['repository_fabrica_password'])) {
      $form_state->unsetValue('repository_fabrica_password');
    }

    parent::validateForm(
      $form,
      $form_state
    ); // TODO: Change the autogenerated stub
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fragaria.datacite')
      ->set(
        'active', $form_state->getValue('active') ? TRUE : FALSE
      )
      ->set(
        'use_test_account', $form_state->getValue('use_test_account') ? TRUE : FALSE
      )
      ->set(
        'doi_prefix', trim($form_state->getValue('doi_prefix') ?? ' ')
      )
      ->set(
        'processor_entity_id', $form_state->getValue('processor_entity_id')
      );

    if ($form_state->getValue('repository_fabrica_user_test') && $form_state->getValue('repository_fabrica_password_test')) {
      $this->config('fragaria.datacite')->set(
        'repository_fabrica_user_test', trim($form_state->getValue('repository_fabrica_user_test') ?? ' ')
      )
        ->set(
          'repository_fabrica_password_test', trim($form_state->getValue('repository_fabrica_password_test') ?? ' ')
        );
    }

    if ($form_state->getValue('repository_fabrica_user') && $form_state->getValue('repository_fabrica_password')) {
      $this->config('fragaria.datacite')->set(
        'repository_fabrica_user_test', trim($form_state->getValue('repository_fabrica_user') ?? ' ')
      )
        ->set(
          'repository_fabrica_password_test', trim($form_state->getValue('repository_fabrica_password') ?? ' ')
        );
    }

    $this->config('fragaria.datacite')->save();
    parent::submitForm($form, $form_state);
  }

}
