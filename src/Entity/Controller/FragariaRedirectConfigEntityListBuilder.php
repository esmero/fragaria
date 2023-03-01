<?php

namespace Drupal\fragaria\Entity\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\fragaria\Entity\FragariaRedirectConfigEntity;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;

/**
 * Provides a list controller for the Fragaria Redirect Config entity.
 *
 * @ingroup fragaria
 */
class FragariaRedirectConfigEntityListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * We override ::render() so that we can add our own content above the table.
   * parent::render() is where EntityListBuilder creates the table using our
   * buildHeader() and buildRow() implementations.
   */
  public function render() {
    $build['description'] = [
      '#markup' => $this->t(
        'Fragaria Module implements Dynamic Redirects to ADOs from a combination of prefix path, a variable entry to match against an Search API field and a suffix.',
      ),
    ];
    $build += parent::render();
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Building the header and content lines for the contact list.
   *
   * Calling the parent::buildHeader() adds a column for the possible actions
   * and inserts the 'edit' and 'delete' links as defined for the entity type.
   */
  public function buildHeader() {
    $header['id'] = $this->t('Fragaria Redirect Entity Config ID');
    $header['label'] = $this->t('Label');
    $header['url'] = $this->t('Example URL(s)');
    $header['active'] = $this->t('Is active ?');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var $entity \Drupal\fragaria\Entity\FragariaRedirectConfigEntity */
    // Build a demo URL so people can see it working
    $value = $this->getOneValuefromSearchAPI($entity);

    $url = $value ? $this->getDemoUrlForItem($entity, $value) : $this->t('We could not create an example URL for this endpoint. The configured Search API field might not have data yet.');
    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $row['url'] = $url && $value ? [
      'data' => [
        '#markup' => $this->t(
          '<a href="@demolink">@demolink</a>.',
          [
            '@demolink' => $url,
          ]
        ),
      ],
    ] : $url;

    $row['active'] = $entity->isActive() ? $this->t('Yes') : $this->t('No');

    return $row + parent::buildRow($entity);
  }

  /**
   * Generates a valid Example URL given a Node UUID.
   *
   * @param \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $entity
   *   The Exposed Metadata Entity we are processing the URL for.
   * @param string $uuid
   *   An UUID of a node of bundle type configured for this Exposed endpoint.
   *
   * @return \Drupal\Core\GeneratedUrl|null|string
   *   A Drupal URL if we have enough arguments or NULL if not.
   */
  private function getDemoUrlForItem(FragariaRedirectConfigEntity $entity, string $value) {
    $url = NULL;
    try {
      $url = \Drupal::urlGenerator()
        ->generateFromRoute(
          'fragaria_redirect.' . $entity->id(),
          [
            'argument' => $value,
          ]
        );
    }
    catch (\Exception $e) {
      $this->messenger()->addError('We could not generate an example URL for the Fragaria Redirect Entity @label',[
        '@label' => $entity->label(),
      ]);
    }

    return $url;
  }

  /**
   * Fetches a single value for configured Search API foeld.
   *
   * @param \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $entity
   *   The Exposed Metadata Entity we are getting an example Node UUID for.
   *
   * @return null|string
   *   A Valid string from a search API field.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getOneValuefromSearchAPI(FragariaRedirectConfigEntity $entity) {

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->load($entity->getSearchApiIndex());



    /*
     *  // Also check whether the underlying property actually (still) exists.
      $datasource_id = $field->getDatasourceId();
      $property = NULL;
      if ($datasource_id === NULL || $this->isValidDatasource($datasource_id)) {
        $properties = $this->getPropertyDefinitions($datasource_id);
        $property = \Drupal::getContainer()
          ->get('search_api.fields_helper')
          ->retrieveNestedProperty($properties, $field->getPropertyPath());
      }
      if (!$property) {
        $this->removeField($field_id);
      }
    }
     */

    foreach ($indexes as $index) {
      // Create the query.
      $query = $index->query([
        'limit' => 1,
        'offset' => 0,
      ]);

      $query = $index->query();
      $query->range(0, 1);

      $allfields_translated_to_solr = $index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($index);

      $query->addCondition($entity->getSearchApiField(), NULL, '<>');
      $result = $query->execute();

    }

    return is_array($result) ? reset($result) : $result;
  }

}
