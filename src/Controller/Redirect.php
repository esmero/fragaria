<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 4/23/18
 * Time: 9:02 PM
 */

namespace Drupal\fragaria\Controller;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Utility\Error;
use Drupal\fragaria\Entity\FragariaRedirectConfigEntity;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

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
   * The access manager.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected AccessManagerInterface $accessManager;

  /**
   * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
   */
  private UrlMatcherInterface $accessUnawareRouter;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;


  /**
   * Constructs a new WebhookController object.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entitytype_manager,
    ParseModePluginManager $parse_mode_manager,
    RouteMatchInterface $route_match,
    AccessManagerInterface $access_manager,
    RedirectDestinationInterface $redirect_destination,
    UrlMatcherInterface $access_unaware_router) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entitytype_manager;
    $this->parseModeManager = $parse_mode_manager;
    $this->routeMatch = $route_match;
    $this->accessManager = $access_manager;
    $this->redirectDestination = $redirect_destination;
    $this->accessUnawareRouter = $access_unaware_router;
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
      $container->get('access_manager'),
      $container->get('redirect.destination'),
      $container->get('router.no_access_checks')
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
   * Makes a sub request to retrieve a custom error page.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   * @param string $custom_path
   *   The custom path to which to make a sub request for this error message.
   */
  protected function makeSubrequestToCustom404(ExceptionEvent $event, string $custom_path): void {
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

    $request = $event->getRequest();
    $exception = $event->getThrowable();

    try {
      // Reuse the exact same request (so keep the same URL, keep the access
      // result, the exception, et cetera) but override the routing information.
      // This means that aside from routing, this is identical to the master
      // request. This allows us to generate a response that is executed on
      // behalf of the master request, i.e. for the original URL. This is what
      // allows us to e.g. generate a 404 response for the original URL; if we
      // would execute a subrequest with the 404 route's URL, then it'd be
      // generated for *that* URL, not the *original* URL.
      $sub_request = clone $request;

      // The routing to the 404 page should be done as GET request because it is
      // restricted to GET and POST requests only. Otherwise, a DELETE request
      // would for example trigger a method not allowed exception.
      $request_context = clone ($this->accessUnawareRouter->getContext());
      $request_context->setMethod('GET');
      $this->accessUnawareRouter->setContext($request_context);

      $sub_request->attributes->add($this->accessUnawareRouter->match($url));

      // Add to query (GET) or request (POST) parameters:
      // - 'destination' (to ensure e.g. the login form in a 403 response
      //   redirects to the original URL)
      // - '_exception_statuscode'
      $parameters = $sub_request->isMethod('GET') ? $sub_request->query : $sub_request->request;
      $parameters->add($this->redirectDestination->getAsArray() + ['_exception_statuscode' => Response::HTTP_NOT_FOUND]);

      $response = $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST);
      // Only 2xx responses should have their status code overridden; any
      // other status code should be passed on: redirects (3xx), error (5xx)…
      // @see https://www.drupal.org/node/2603788#comment-10504916
      if ($response->isSuccessful()) {
        $response->setStatusCode(Response::HTTP_NOT_FOUND);
      }

      // Persist the exception's cacheability metadata, if any. If the exception
      // itself isn't cacheable, then this will make the response uncacheable:
      // max-age=0 will be set.
      if ($response instanceof CacheableResponseInterface) {
        $response->addCacheableDependency($exception);
      }

      // Persist any special HTTP headers that were set on the exception.
      if ($exception instanceof HttpExceptionInterface) {
        $response->headers->add($exception->getHeaders());
      }

      $event->setResponse($response);
    }
    catch (\Exception $e) {
      // If an error happened in the subrequest we can't do much else. Instead,
      // just log it. The DefaultExceptionSubscriber will catch the original
      // exception and handle it normally.
      $error = Error::decodeException($e);
      $this->getLogger('fragaria')->log($error['severity_level'], Error::DEFAULT_ERROR_MESSAGE, $error);
    }
  }
}
