<?php

namespace Drupal\fragaria\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Defines the Fragaria Redirect Config entity.
 *
 * This entity defines a redirect route/pattern/source.
 * Allows dynamic routes to redirect to ADOs using Solr field matches.
 *
 * @ConfigEntityType(
 *   id = "fragariaredirect_entity",
 *   label = @Translation("Fragaria Redirect Entity Configuration"),
 *   handlers = {
 *     "list_builder" = "Drupal\fragaria\Entity\Controller\FragariaRedirectConfigEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\fragaria\Form\FragariaRedirectConfigEntityForm",
 *       "edit" = "Drupal\fragaria\Form\FragariaRedirectConfigEntityForm",
 *       "delete" = "Drupal\fragaria\Form\FragariaRedirectConfigEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\fragaria\FragariaRedirectConfigEntityHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "fragariaredirect_entity",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "active" = "active",
 *   },
 *  config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "path_prefix",
 *     "search_api_index",
 *     "search_api_field",
 *     "path_suffixes",
 *     "search_api_field_value_prefixes",
 *     "search_api_field_value_suffixes",
 *     "variable_path_suffix",
 *     "redirect_http_code",
 *     "cache",
 *     "active",
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/archipelago/fragariaredirect/{fragariaredirect_entity}/edit",
 *     "add-form" = "/admin/config/archipelago/fragariaredirect/add",
 *     "delete-form" = "/admin/config/archipelago/fragariaredirect/{fragariaredirect_entity}/delete",
 *     "collection" = "/admin/config/archipelago/fragariaredirect",
 *   }
 * )
 */
class FragariaRedirectConfigEntity extends ConfigEntityBase implements FragariaConfigInterface {

  use DependencySerializationTrait;
  /**
   * The ID of the Fragaria Redirect Config Entity.
   *
   * @var string
   */

  protected $id;

  /**
   * The human-readable name of the form or view mode.
   *
   * @var string
   */
  protected string $label;

  /**
   * Prefix this route will respond to
   *
   * @var string
   */
  public string $path_prefix;

  /**
   * Additional Prefixes this route will respond to
   *
   * @var array
   */
  public array $path_suffixes = [];


  /**
   * Set of static prefixes to be added to the passed argument of the route.
   *
   * This is needed in case the indexed value does not match 1:1 with the route
   * argument, e.g argument is 111, the actual indexed value is namespace:111
   *
   * @var array
   */
  public array $search_api_field_value_prefixes = [];


  /**
   * Set of static suffixes to be added to the passed argument of the route.
   *
   * This is needed in case the indexed value does not match 1:1 with the route
   * argument, e.g argument is 123, the actual indexed value is 123:other
   *
   * @var array
   */
  public array $search_api_field_value_suffixes = [];


  /**
   * Don't use extra suffixes, add a single {catch_all} extra suffix.
   *
   * @var bool
   */
  public bool $variable_path_suffix = FALSE;

  /**
   * The Search API Index ID of the field.
   *
   * @var string
   */
  protected $search_api_index = NULL;

  /**
   * The Search API Field present in the index
   *
   * @var string
   */
  protected $search_api_field = NULL;

  /**
   * The Type of HTTP redirect Code to use
   *
   * @var string
   */
  protected $redirect_http_code = '303';

  /**
   * If the Config Entity is active or not.
   *
   * @var bool
   */
  protected $active = TRUE;

  /**
   * The Label for this config entity.
   *
   * @return string
   *   The label.
   */
  public function getLabel(): string {
    return $this->label;
  }

  /**
   * Label setter.
   *
   * @param string $label
   *   The config entity label.
   */
  public function setLabel(string $label): void {
    $this->label = $label;
  }

  /**
   * {@inheritdoc}
   */
  public static function sort(
    ConfigEntityInterface $a,
    ConfigEntityInterface $b
  ) {
    /** @var \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $a */
    /** @var \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $b */
    // Sort by the type the label.
    $a_type = $a->getLabel();
    $b_type = $b->getLabel();
    $type_order = strnatcasecmp($a_type, $b_type);
    return $type_order != 0 ? $type_order : parent::sort($a, $b);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependency('module', $this->getEntityType()->getProvider());
    $this->addDependency('module', \Drupal::entityTypeManager()->getDefinition(
      'node')->getProvider());
    //  $dependencies[$this->index->getConfigDependencyKey()][] = $this->index->getConfigDependencyName();
    return $this->dependencies;
  }


  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update); // TODO: Change the autogenerated stub
    \Drupal::entityTypeManager()->clearCachedDefinitions();
    \Drupal::service("router.builder")->rebuild();
  }


  public static function postDelete(EntityStorageInterface $storage,
    array $entities
  ) {
    parent::postDelete(
      $storage, $entities
    ); // TODO: Change the autogenerated stub
    \Drupal::entityTypeManager()->clearCachedDefinitions();
    \Drupal::service("router.builder")->rebuild();
  }


  /**
   * @return string
   */
  public function getPathPrefix(): string {
    return $this->path_prefix;
  }

  /**
   * @return array
   */
  public function getPathSuffixes(): array {
    return $this->path_suffixes;
  }

  /**
   * @return string
   */
  public function getSearchApiIndex(): string {
    return $this->search_api_index;
  }

  /**
   * @return string
   */
  public function getSearchApiField(): string {
    return $this->search_api_field;
  }

  /**
   * @param array $path_suffixes
   */
  public function setPathSuffixes(array $path_suffixes): void {
    $this->path_suffixes = $path_suffixes;
  }

  /**
   * Checks if this Config is active.
   *
   * @return bool
   *   True if active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Sets the active flag.
   *
   * @param bool $active
   *   True to set Active.
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }

  /**
   * @return string
   */
  public function getRedirectHttpCode(): string {
    return $this->redirect_http_code;
  }

  /**
   * @return array
   */
  public function getSearchApiFieldValuePrefixes(): array {
    return $this->search_api_field_value_prefixes;
  }

  /**
   * @return bool
   */
  public function getVariablePathSuffix(): bool {
    return $this->variable_path_suffix;
  }

  /**
   * @return array
   */
  public function getSearchApiFieldValueSuffixes(): array {
    return $this->search_api_field_value_suffixes;
  }

  /**
   * @param array $search_api_field_value_suffixes
   */
  public function setSearchApiFieldValueSuffixes(array $search_api_field_value_suffixes
  ): void {
    $this->search_api_field_value_suffixes = $search_api_field_value_suffixes;
  }

  /**
   * @param array $search_api_field_value_prefixes
   */
  public function setSearchApiFieldValuePrefixes(array $search_api_field_value_prefixes
  ): void {
    $this->search_api_field_value_prefixes = $search_api_field_value_prefixes;
  }


}
