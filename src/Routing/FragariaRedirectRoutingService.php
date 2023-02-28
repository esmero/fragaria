<?php
namespace fragaria;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class FragariaRedirectRoutingService {

  use StringTranslationTrait;
  use MessengerTrait;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * FragariaRedirectRoutingService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface      $entity_type_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface          $config_factory
   */

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }


  /**
   * Returns route Collection;
   *
   * @return \Symfony\Component\Routing\RouteCollection;
   *   An array of route objects.
   */
  public function redirect_routes() {
    $routes = [];
    $query = $this->entityTypeManager->getStorage('fragariaredirect_entity')->getQuery();
    $ids = $query->execute();
    /* @var \Drupal\fragaria\Entity\FragariaRedirectConfigEntity[] $entities */
    $entities = $this->entityTypeManager->getStorage('fragariaredirect_entity')->loadMultiple($ids);
    $route_collection = new RouteCollection();
    /* @TODO
     * - sanitize prefix, suffixes
     * - check if we can use the prefixed one as base route for the other
    */
    foreach ($entities as $entity) {
      if ($entity->isActive()) {
        $prefix = $entity->getPathPrefix();
        $suffixes = $entity->getPathSuffixes();
        $route = new Route(
          '/' . $prefix . '/{key}',
          [
            '_controller' => 'Drupal\fragaria\Controller\Redirect::redirect',
          ],
          [
            '_access' => 'TRUE',
          ]
        );
        $route->setDefault('fragariaredirect_entity', $entity->id());
        $route_collection->add('fragaria_redirect.'.$entity->id(), $route);
        foreach ($suffixes as $suffix) {
          $route_suffix = clone $route;
          $route_suffix->setPath($route_suffix->getPath().'/'.$suffix);
          $route_collection->add('fragaria_redirect.'.$entity->id().'.'.$suffix, $route);
        }
      }
    }
    return $route_collection;
  }

}
