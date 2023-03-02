<?php
namespace Drupal\fragaria\Routing;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
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
  public function redirect_routes(): RouteCollection {
    $route_collection = new RouteCollection();
    try {
      $query = $this->entityTypeManager->getStorage('fragariaredirect_entity')
        ->getQuery();
    } catch (InvalidPluginDefinitionException $e) {
      return $route_collection;
    } catch (PluginNotFoundException $e) {
      return $route_collection;
    }
    $ids = $query->execute();
    /* @var \Drupal\fragaria\Entity\FragariaRedirectConfigEntity[] $entities */
    try {
      $entities = $this->entityTypeManager->getStorage(
        'fragariaredirect_entity'
      )->loadMultiple($ids);
    } catch (InvalidPluginDefinitionException $e) {
      return $route_collection;
    } catch (PluginNotFoundException $e) {
      return $route_collection;
    }

    /* @TODO
     * - sanitize prefix, suffixes
     * - check if we can use the prefixed one as base route for the other
    */
    foreach ($entities as $entity) {
      if ($entity->isActive()) {
        $prefix = $entity->getPathPrefix();
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
        foreach ($suffixes as $key => $suffix) {
          $suffix = trim(trim($suffix), '/');
          $route_suffix = clone $route;
          $route_suffix->setPath($route_suffix->getPath().'/'.$suffix);
          $route_collection->add('fragaria_redirect.'.$entity->id().'.'.$key, $route_suffix);
        }
      }
    }
    return $route_collection;
  }

}
