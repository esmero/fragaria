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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
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
        $prefixes = $entity->getPathPrefixes();
        $fragaria_routes = new \WeakMap();
        if ($entity->isDoReplacement()) {
          foreach ($prefixes as $prefix) {
            $route = new Route(
              '/' . $prefix . '/{key}',
              [
                '_controller' => 'Drupal\fragaria\Controller\Redirect::redirect_do',
              ],
              [
                '_access' => 'TRUE',
              ]
            );
            $route->setRequirement('key', "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}");
            $options['parameters']['key'] = ['type' => 'entity:node'];
            $options['parameters']['resource_type'] = ['type' => 'ado'];
            $route->setOptions($options);
            $route->setDefault('fragariaredirect_entity', $entity->id());
            $fragaria_routes[$route] = TRUE;
            $name = 'fragaria_redirect.' . md5($prefix) .'-'. $entity->id();
            $route_collection->add($name, $route);
          }
        }
        else {
          foreach ($prefixes as $prefix) {
            $route = new Route(
              '/' . $prefix . '/{key}',
              [
                '_controller' => 'Drupal\fragaria\Controller\Redirect::redirect_processor',
              ],
              [
                '_access' => 'TRUE',
              ]
            );
            $name = 'fragaria_redirect.' . md5($prefix) .'-'. $entity->id();
            $route->setDefault('fragariaredirect_entity', $entity->id());
            if ($entity->getAllowEmptyVariable()) {
              $route->setDefault('key', '');
            }
            $fragaria_routes[$route] = $name;
            $route_collection->add($name, $route);
          }
        }
        if (!$entity->isDoReplacement()) {
          if ($entity->getVariablePathSuffix()) {
            foreach ($fragaria_routes as $local_route => $name) {
              $route_variable = clone $local_route;
              $route_variable->setPath(
                $route_variable->getPath() . '/{catch_all}'
              );
              $route_variable->setOption(
                '_controller',
                'Drupal\fragaria\Controller\Redirect::redirect_processor_variable'
              );
              $route_variable->setDefault('catch_all', '');
              // Because of the catch call {key} can't have a default
              $route_variable->setDefault('key', NULL);
              $route_collection->add(
                $name. '.variable',
                $route_variable
              );
            }
          }
          else {
            $suffixes = $entity->getPathSuffixes();
            foreach ($suffixes as $key => $suffix) {
              foreach ($fragaria_routes as $local_route => $name) {
                $suffix = trim(trim($suffix), '/');
                $route_suffix = clone $local_route;
                $route_suffix->setPath($route_suffix->getPath() . '/' . $suffix);
                // Because of the Fixed Suffix {key} can't also have a default
                $route_suffix->setDefault('key', NULL);
                $route_collection->add(
                  $name . '.' . $key, $route_suffix
                );
              }
            }
          }
        }
        unset($fragaria_routes);
      }
    }
    return $route_collection;
  }

}
