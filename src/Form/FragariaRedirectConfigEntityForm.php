<?php

namespace Drupal\fragaria\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;

/**
 * Form handler for metadataexpose_entity config entity add and edit.
 */
class FragariaRedirectConfigEntityForm extends EntityForm {


  /**
   * FragariaRedirectConfigEntityForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
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
    $field_options = [];


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
      'path_prefix' => [
        '#type' => 'textfield',
        '#title' => $this->t('The Prefix (that follows your domain) for the Redirect Route.'),
        '#required' => TRUE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? $fragariaredirect_config->getPathPrefix() : NULL,
      ],
      'path_suffixes_element' => [
        '#type' => 'textarea',
        '#title' => $this->t('The Suffixes (that follow the prefix + the variable part) for the Redirect Route.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode("\n", $fragariaredirect_config->getPathSuffixes()): NULL,
        '#description' => $this->t('Enter one by line. This configuration option is not required.'),
      ],
      'search_api_index' => [
        '#type' => 'select',
        '#options' => $indexes_option,
        '#title' => $this->t('The Search API Index where the Field that will be matched against the variable part of the route exists.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode("\n", $fragariaredirect_config->getSearchApiIndex()): NULL,
      ],
      'search_api_field' => [
        '#type' => 'select',
        '#options' => $field_options,
        '#title' => $this->t('The Search API Field that will be matched against the variable part of the route.'),
        '#required' => FALSE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode("\n", $fragariaredirect_config->getSearchApiField()): NULL,
      ],
      'redirect_http_code' => [
        '#type' => 'select',
        '#options' => [
          '301' => $this->t('Permanent Redirect'),
          '303' => $this->t('Temporary Redirect (forced GET)'),
        ],
        '#title' => $this->t('Type of HTTP redirect to perform.'),
        '#required' => TRUE,
        '#default_value' => (!$fragariaredirect_config->isNew()) ? implode("\n", $fragariaredirect_config->getRedirectHttpCode()): NULL,
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

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove button and internal Form API values from submitted values.
    $form_state->cleanValues();
    $suffixes = $form_state->getValue('path_suffixes_element','');
    $suffixes = array_map(function ($line) { return $line ? trim($line) : NULL;}, explode("\n", $suffixes));
    $suffixes = array_filter($suffixes);
    $this->entity = $this->buildEntity($form, $form_state);
    $this->entity->setPathSuffixes(is_array($suffixes) ? $suffixes : []);
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

}
