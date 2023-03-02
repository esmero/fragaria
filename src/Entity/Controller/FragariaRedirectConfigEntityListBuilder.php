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
    $value = NULL;
    if ($entity->isActive()) {
      $value = $this->getOneValuefromSearchAPI($entity);

      $url = ($value !== NULL)
        ? $this->getDemoUrlForItem($entity, $value)
        : $this->t(
          'We could not create an example URL for this endpoint. The configured Search API field might not have data yet.'
        );
    }
    else {
      $url = $this->t(
        'Inactive Redirects can not generate Route URLs');
    };
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
    /*
     *
     *  $prefix = $entity->getPathPrefix();
        $prefix = trim(trim($prefix), '/');
        $suffixes = $entity->getPathSuffixes();
        $route = new Route(
          '/' . $prefix . '/{key}',
          [
            '_controller' => 'Drupal\fragaria\Controller\Redirect::redirect_processor',
          ],
          [
            '_access' => 'TRUE',
          ]
        );
        $route->setDefault('fragariaredirect_entity', $entity->id());
        $route_collection->add('fragaria_redirect.'.$entity->id(), $route);
        foreach ($suffixes as $suffix) {
          $suffix = trim(trim($suffix), '/');
          $route_suffix = clone $route;
          $route_suffix->setPath($route_suffix->getPath().'/'.$suffix);
          $route_collection->add('fragaria_redirect.'.$entity->id().'.'.$suffix, $route);
        }
     */
    try {
      $url = \Drupal::urlGenerator()
        ->generateFromRoute(
          'fragaria_redirect.' . $entity->id(),
          [
            'key' => $value,
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
    $index = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->load($entity->getSearchApiIndex());

    $value = NULL;
    if ($index) {
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
      $query->setOption('search_api_retrieved_field_values', [$entity->getSearchApiField() => $entity->getSearchApiField()]);
      $query->addCondition($entity->getSearchApiField(), NULL, '<>');
      $results = $query->execute();
      foreach($results->getResultItems() as $itemid => $resultItem) {
        // We can not allow any extraction or entity load happening here
        // The Search API entity loading will interrupt other sessions/active NODEs
        // and will disable EDIT/any management on the ADO that is using this
        // Extension. So we will get what is in the index (solr)
        // Will have no issues with this.
        // This is related to "QueryInterface::PROCESSING_FULL" but is needed to respect
        // Permissions. If not this will get anything from the Server
        // Including hidden/unpublished things.
        foreach ($resultItem->getFields(FALSE) as $key => $field) {
          if ($key == $entity->getSearchApiField()) {
            $value = $field->getValues();
          }
        }
      }
    }

    return is_array($value) ? reset($value) : $value;
  }

}
