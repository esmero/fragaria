<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 4/23/18
 * Time: 9:02 PM
 */

namespace Drupal\fragaria\Controller;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\fragaria\Entity\FragariaRedirectConfigEntity;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
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
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

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
      // Complete match without Domain. WE do not allow the domain to be used
      // That can be simulated with a prefix.
      $pathinfo = $this->routeMatch->getRouteObject()->getPath();
      $object = $this->searchAPIfindKey($key, $pathinfo, $entity);
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

  /**
   * Capture the payload. Send to that happy place.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A simple string and Redirect response.
   */
  public function redirect_do(Request $request, ContentEntityInterface $key) {
    $entity = $this->getFragariaEntityFromRouteMatch($this->routeMatch);
    if ($entity) {
      if ($key) {
        $url = $key->toUrl('canonical', ['absolute' => FALSE])->toString();
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



  /**
   * Capture the payload. Send to that happy place.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A simple string and Redirect response.
   */
  public function redirect_processor_variable(Request $request, $key, $catch_all) {
    return $this->redirect_processor($request, $key);
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
   * @param string $key
   *    The variable part
   * @param string $path
   *    The complete path. will be /prefixes()/{key}/suffixes
   * @param \Drupal\fragaria\Entity\FragariaRedirectConfigEntity $entity
   *
   * @return mixed|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function searchAPIfindKey(string $key, string $path, FragariaRedirectConfigEntity $entity) {

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $index = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->load($entity->getSearchApiIndex());

    $decomposed_path =  array_filter(explode("/", $path));
    $decomposed_static_prefix = '/';
    $decomposed_static_suffix = '/';
    $found_key = false;
    foreach ($decomposed_path as $segment) {
        if ($found_key) {
          $decomposed_static_suffix = $decomposed_static_suffix . $segment . '/';
        }
        else {
          if ($segment == "{key}") {
            $found_key = TRUE;
          }
          else {
            $decomposed_static_prefix = $decomposed_static_prefix . $segment . '/';
          }
        }
    }

    $value = NULL;
    if ($index) {
      $query = $index->query();
      $query->range(0, 1);
      $query->setOption(
        'search_api_retrieved_field_values',
        [$entity->getSearchApiField() => $entity->getSearchApiField()]
      );
      $segments_in_pattern = $entity->getSegmentsInPattern();
      // Should never happen but people could insert a config entity manually
      // via drush. If so we default to variable part
      if (empty($segments_in_pattern)) {
        $segments_in_pattern = ['variable'];
      }
      if (in_array('variable', $segments_in_pattern)) {
        $value_with_prefixes = [$key];
        if ($entity->getSearchApiFieldValuePrefixes()) {
          foreach ($entity->getSearchApiFieldValuePrefixes() as $prefix) {
            $value_with_prefixes[] = $prefix . $key;
          }
        }
        if ($entity->getSearchApiFieldValueSuffixes()) {
          foreach ($entity->getSearchApiFieldValueSuffixes() as $suffix) {
            foreach ($value_with_prefixes as $prefixed) {
              $value_with_prefixes[] = $prefixed . $suffix;
            }
          }
        }
      }
      // Now for the extra prefix part.
      if (in_array('prefixes', $segments_in_pattern)) {
        foreach ($value_with_prefixes as $prefixed) {
        }
      }


      if (count($value_with_prefixes) == 1) {
        $query->addCondition(
          $entity->getSearchApiField(), $value_with_prefixes[0]
        );
      }
      else {
        $query->addCondition(
          $entity->getSearchApiField(), $value_with_prefixes, 'IN'
        );
      }

      $results = $query->execute();
      foreach ($results->getResultItems() as $itemid => $resultItem) {
        return $resultItem->getOriginalObject()->getValue();
      }
    }
    return NULL;
  }


  /**
   * Makes a subrequest to retrieve the custom error page.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   * @param string $custom_path
   *   The custom path to which to make a subrequest for this error message.
   * @param int $status_code
   *   The status code for the error being handled.
   */
  protected function makeSubrequestToCustom404(ExceptionEvent $event, $custom_path) {
    $url = Url::fromUserInput($custom_path);
    if ($url->isRouted()) {
      $access_result = $this->accessManager->checkNamedRoute($url->getRouteName(), $url->getRouteParameters(), NULL, TRUE);
      $request = $event->getRequest();
      if (!$request->attributes->has(AccessAwareRouterInterface::ACCESS_RESULT)) {
        $request->attributes->set(AccessAwareRouterInterface::ACCESS_RESULT, $access_result);
      }
      else {
        $existing_access_result = $request->attributes->get(AccessAwareRouterInterface::ACCESS_RESULT);
        if ($existing_access_result instanceof RefinableCacheableDependencyInterface) {
          $existing_access_result->addCacheableDependency($access_result);
        }
      }

      // Only perform the subrequest if the custom path is actually accessible.
      if (!$access_result->isAllowed()) {
        return;
      }
    }

    $this->makeSubrequest($event, $custom_path, Response::HTTP_NOT_FOUND);
  }
}
