<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 4/23/18
 * Time: 9:02 PM
 */

namespace Drupal\fragaria\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\fragaria\Entity\FragariaRedirectConfigEntity;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class Redirect.
 */
class Redirect extends ControllerBase {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new WebhookController object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entitytype_manager,
    ParseModePluginManager $parse_mode_manager, RouteMatchInterface $route_match) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entitytype_manager;
    $this->parseModeManager = $parse_mode_manager;
    $this->routeMatch = $route_match;


  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.search_api.parse_mode'),
      $container->get('current_route_match'),
    );
  }

  /**
   * Capture the payload. Send to that happy place.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A simple string and Redirect response.
   */
  public function redirect_processor(Request $request, $key) {
    $entity = $this->getFragariaEntityFromRouteMatch($this->routeMatch);
    if ($entity) {
      $object = $this->searchAPIfindKey($key, $entity);
      if ($object) {
        $url = $object->toUrl('canonical', ['absolute' => FALSE])->toString();
        $response = new RedirectResponse($url, (int) $entity->getRedirectHttpCode());
        return $response;
      }
      else {
        throw new NotFoundHttpException();
      }
    }
    else {
      throw new NotFoundHttpException();
    }
  }


  public function getFragariaEntityFromRouteMatch(RouteMatchInterface $route_match) {
    $entity = NULL;
    if ($route_match->getParameter('fragariaredirect_entity') !== NULL) {
      $entity_id = $route_match->getParameter('fragariaredirect_entity');
      /* @var FragariaRedirectConfigEntity|null $entity */
      $entity = $this->entityTypeManager->getStorage('fragariaredirect_entity')->load($entity_id);
    }

    return $entity;
  }

  /**
   * @param                                                      $key
   * @param \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $entity
   *
   * @return mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function searchAPIfindKey($key, FragariaRedirectConfigEntity $entity) {

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $index = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->load($entity->getSearchApiIndex());

    $value = NULL;
    if ($index) {
      $query = $index->query();
      $query->range(0, 1);
      $query->setOption(
        'search_api_retrieved_field_values',
        [$entity->getSearchApiField() => $entity->getSearchApiField()]
      );
      $query->addCondition($entity->getSearchApiField(), $key);
      $results = $query->execute();
      foreach ($results->getResultItems() as $itemid => $resultItem) {
        return $resultItem->getOriginalObject()->getValue();
      }
    }
    return NULL;
  }


}
