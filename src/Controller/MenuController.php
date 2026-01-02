<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend_menu\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\jsonapi_frontend\Service\PathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Menu tree endpoint for jsonapi_frontend.
 */
final class MenuController extends ControllerBase {

  private const CONTENT_TYPE = 'application/vnd.api+json; charset=utf-8';

  public function __construct(
    private readonly MenuLinkTreeInterface $menuLinkTree,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly PathResolver $resolver,
    private readonly RequestStack $requestStackService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('menu.link_tree'),
      $container->get('entity_type.manager'),
      $container->get('jsonapi_frontend.path_resolver'),
      $container->get('request_stack'),
    );
  }

  public function menu(Request $request, string $menu): CacheableJsonResponse {
    $menu_entity = $this->entityTypeManagerService->getStorage('menu')->load($menu);
    if (!$menu_entity) {
      return $this->errorResponse(
        status: 404,
        title: 'Not Found',
        detail: 'Menu not found.',
      );
    }

    $langcode = $request->query->get('langcode');
    $langcode = is_string($langcode) && $langcode !== '' ? $langcode : NULL;

    $active_path = $request->query->get('path');
    $active_path = is_string($active_path) && $active_path !== '' ? $this->normalizePath($active_path) : NULL;

    $include_resolve = $request->query->get('resolve');
    $include_resolve = !(is_string($include_resolve) && ($include_resolve === '0' || strtolower($include_resolve) === 'false'));

    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks();

    $min_depth = $request->query->get('min_depth');
    if (is_string($min_depth) && ctype_digit($min_depth)) {
      $parameters->setMinDepth((int) $min_depth);
    }

    $max_depth = $request->query->get('max_depth');
    if (is_string($max_depth) && ctype_digit($max_depth)) {
      $parameters->setMaxDepth((int) $max_depth);
    }

    $parent = $request->query->get('parent');
    if (is_string($parent) && $parent !== '') {
      $parameters->setRoot($parent);
      $parameters->excludeRoot();
    }

    $tree = $this->menuLinkTree->load($menu, $parameters);

    if (!$tree) {
      $response = new CacheableJsonResponse([
        'data' => [],
        'meta' => [
          'menu' => $menu,
          'active_trail' => [],
          'langcode' => $langcode,
          'path' => $active_path,
          'resolve' => $include_resolve,
        ],
      ], 200, [
        'Content-Type' => self::CONTENT_TYPE,
      ]);

      $cacheable = new CacheableMetadata();
      $cacheable->setCacheMaxAge($this->getCacheMaxAge());
      $cacheable->addCacheTags(['config:jsonapi_frontend.settings']);
      $cacheable->addCacheableDependency($menu_entity);
      $cacheable->addCacheContexts([
        'url.query_args:path',
        'url.query_args:langcode',
        'url.query_args:resolve',
        'url.query_args:min_depth',
        'url.query_args:max_depth',
        'url.query_args:parent',
        'url.site',
      ]);
      $this->addLanguageFallbackCacheContext($cacheable, $langcode);
      $cacheable->applyTo($response);
      $this->applySecurityHeaders($response, $cacheable->getCacheMaxAge());

      return $response;
    }

    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    $max_age = $this->getCacheMaxAge();

    $cacheability = new CacheableMetadata();
    $cacheability->setCacheMaxAge($max_age);
    $cacheability->addCacheTags(['config:jsonapi_frontend.settings']);
    $cacheability->addCacheableDependency($menu_entity);
    $cacheability->addCacheContexts([
      'url.query_args:path',
      'url.query_args:langcode',
      'url.query_args:resolve',
      'url.query_args:min_depth',
      'url.query_args:max_depth',
      'url.query_args:parent',
      'url.site',
    ]);
    $this->addLanguageFallbackCacheContext($cacheability, $langcode);

    $items = $this->buildItems($tree, $langcode, $include_resolve, $cacheability);

    $active_trail = [];
    if ($active_path !== NULL) {
      $active_trail = $this->computeActiveTrail($items, $active_path);
      $this->applyActiveFlags($items, $active_trail);
    }

    $response = new CacheableJsonResponse([
      'data' => $items,
      'meta' => [
        'menu' => $menu,
        'active_trail' => $active_trail,
        'langcode' => $langcode,
        'path' => $active_path,
        'resolve' => $include_resolve,
      ],
    ], 200, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $cacheability->applyTo($response);
    $this->applySecurityHeaders($response, $max_age);

    return $response;
  }

  /**
   * Build nested menu items.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   Menu tree elements.
   */
  private function buildItems(array $tree, ?string $langcode, bool $include_resolve, CacheableMetadata $cacheability): array {
    $items = [];

    foreach ($tree as $element) {
      if ($element->access !== NULL && !$element->access instanceof AccessResultInterface) {
        throw new \DomainException('MenuLinkTreeElement::access must be either NULL or an AccessResultInterface object.');
      }

      if ($element->access instanceof AccessResultInterface) {
        $cacheability->addCacheableDependency($element->access);
        if (!$element->access->isAllowed()) {
          continue;
        }
      }

      $link = $element->link;
      if ($link instanceof CacheableDependencyInterface) {
        $cacheability->addCacheableDependency($link);
      }
      $url_object = $link->getUrlObject();
      $external = $url_object->isExternal();

      $generated = $url_object->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      $url = $generated->getGeneratedUrl();
      if (!$external) {
        $url = $this->normalizeInternalUrl($url);
      }

      $resolve = NULL;
      if ($include_resolve && !$external) {
        $resolve = $this->resolver->resolve($url, $langcode);

        // If the core resolver can't map this path to an entity/view/redirect,
        // treat it as a Drupal-rendered route for hybrid navigation.
        if (empty($resolve['resolved'])) {
          $canonical = $this->normalizePath($url);
          $resolve = [
            'resolved' => TRUE,
            'kind' => 'route',
            'canonical' => $canonical,
            'entity' => NULL,
            'redirect' => NULL,
            'jsonapi_url' => NULL,
            'data_url' => NULL,
            'headless' => FALSE,
            'drupal_url' => $this->getDrupalUrl($canonical),
          ];
        }
      }

      $children = $element->subtree ? $this->buildItems($element->subtree, $langcode, $include_resolve, $cacheability) : [];

      $items[] = [
        'id' => $link->getPluginId(),
        'title' => (string) $link->getTitle(),
        'description' => $link->getDescription(),
        'url' => $url,
        'external' => $external,
        'expanded' => (bool) $link->isExpanded(),
        'parent' => $link->getParent() ?: NULL,
        'weight' => (int) $link->getWeight(),
        'active' => FALSE,
        'in_active_trail' => FALSE,
        'resolve' => $resolve,
        'children' => $children,
      ];
    }

    return $items;
  }

  /**
   * Returns plugin ids in the active trail (root → active).
   */
  private function computeActiveTrail(array $items, string $active_path): array {
    $flat = [];
    $this->flattenItems($items, $flat);

    $best_id = NULL;
    $best_len = -1;

    foreach ($flat as $id => $item) {
      if (!empty($item['external'])) {
        continue;
      }

      $url = (string) ($item['url'] ?? '');
      $path = $this->normalizePath($url);
      if ($path === '') {
        continue;
      }

      if ($path === $active_path) {
        $len = strlen($path);
      }
      elseif ($path !== '/' && str_starts_with($active_path, $path . '/')) {
        $len = strlen($path);
      }
      elseif ($path === '/' && $active_path === '/') {
        $len = 1;
      }
      else {
        continue;
      }

      if ($len > $best_len) {
        $best_len = $len;
        $best_id = $id;
      }
    }

    if ($best_id === NULL) {
      return [];
    }

    $trail = [];
    $seen = [];
    $current = $best_id;
    while ($current !== NULL && !isset($seen[$current])) {
      $seen[$current] = TRUE;
      array_unshift($trail, $current);

      $parent = $flat[$current]['parent'] ?? NULL;
      $current = is_string($parent) && $parent !== '' ? $parent : NULL;
    }

    return $trail;
  }

  private function flattenItems(array $items, array &$flat): void {
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $id = $item['id'] ?? NULL;
      if (is_string($id) && $id !== '') {
        $flat[$id] = [
          'id' => $id,
          'url' => $item['url'] ?? '',
          'external' => $item['external'] ?? FALSE,
          'parent' => $item['parent'] ?? NULL,
        ];
      }

      if (!empty($item['children']) && is_array($item['children'])) {
        $this->flattenItems($item['children'], $flat);
      }
    }
  }

  private function applyActiveFlags(array &$items, array $trail): void {
    $trail_set = array_fill_keys($trail, TRUE);
    $active_id = $trail ? end($trail) : NULL;
    $active_id = is_string($active_id) && $active_id !== '' ? $active_id : NULL;

    $this->applyActiveFlagsRecursive($items, $trail_set, $active_id);
  }

  private function applyActiveFlagsRecursive(array &$items, array $trail_set, ?string $active_id): void {
    foreach ($items as &$item) {
      if (!is_array($item)) {
        continue;
      }

      $id = $item['id'] ?? NULL;
      if (is_string($id) && $id !== '') {
        $item['active'] = ($active_id !== NULL && $id === $active_id);
        $item['in_active_trail'] = isset($trail_set[$id]);
      }

      if (!empty($item['children']) && is_array($item['children'])) {
        $this->applyActiveFlagsRecursive($item['children'], $trail_set, $active_id);
      }
    }
  }

  /**
   * Build a JSON:API-style error response.
   */
  private function errorResponse(int $status, string $title, string $detail): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'errors' => [
        [
          'status' => (string) $status,
          'title' => $title,
          'detail' => $detail,
        ],
      ],
    ], $status, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge(0);
    $cacheable->applyTo($response);
    $this->applySecurityHeaders($response, 0);

    return $response;
  }

  private function getCacheMaxAge(): int {
    if (!$this->currentUser()->isAnonymous()) {
      return 0;
    }

    $config = $this->config('jsonapi_frontend.settings');
    $max_age = (int) ($config->get('resolver.cache_max_age') ?? 0);

    return max(0, $max_age);
  }

  private function addLanguageFallbackCacheContext(CacheableMetadata $cacheability, ?string $langcode): void {
    if ($langcode !== NULL) {
      return;
    }

    $config = $this->config('jsonapi_frontend.settings');
    $fallback = (string) ($config->get('resolver.langcode_fallback') ?? 'site_default');
    if ($fallback === 'current') {
      $cacheability->addCacheContexts(['languages:language_content']);
    }
  }

  private function applySecurityHeaders(CacheableJsonResponse $response, int $max_age): void {
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    if ($max_age > 0) {
      $response->headers->set('Cache-Control', 'public, max-age=' . $max_age);
    }
    else {
      $response->headers->set('Cache-Control', 'no-store');
    }
  }

  private function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '') {
      return '';
    }

    // Limit input length to avoid wasting work on unbounded strings.
    if (strlen($path) > 2048) {
      return '';
    }

    // Strip scheme/host if a full URL was provided.
    if (preg_match('#^[a-z][a-z0-9+\\-.]*://#i', $path)) {
      $parsed = parse_url($path);
      if (is_array($parsed) && isset($parsed['path'])) {
        $path = (string) $parsed['path'];
        if (!empty($parsed['query'])) {
          $path .= '?' . $parsed['query'];
        }
      }
    }

    $parsed = UrlHelper::parse($path);
    $path = (string) ($parsed['path'] ?? '');

    if ($path === '') {
      return '';
    }

    if ($path[0] !== '/') {
      $path = '/' . $path;
    }

    $path = preg_replace('#/+#', '/', $path) ?? $path;

    if ($path !== '/' && str_ends_with($path, '/')) {
      $path = rtrim($path, '/');
    }

    return $path;
  }

  private function getDrupalUrl(string $path): string {
    $config = $this->config('jsonapi_frontend.settings');
    $base_url = $config->get('drupal_base_url');

    if (empty($base_url)) {
      $request = $this->requestStackService->getCurrentRequest();
      $base_url = $request ? $request->getSchemeAndHttpHost() : '';
    }

    $base_url = rtrim((string) $base_url, '/');

      return $base_url . $path;
  }

  /**
   * Normalizes an internal (non-external) URL string to a safe path+query.
   *
   * This is used to avoid passing absolute URLs (scheme/host) into the resolver
   * and to keep frontend navigation paths consistent.
   */
  private function normalizeInternalUrl(string $url): string {
    $url = trim($url);
    if ($url === '') {
      return '';
    }

    if (strlen($url) > 2048) {
      return '';
    }

    // Drop fragments.
    if (str_contains($url, '#')) {
      $url = strstr($url, '#', TRUE) ?: '';
    }

    // Strip scheme/host if a full URL was provided.
    if (preg_match('#^[a-z][a-z0-9+\\-.]*://#i', $url)) {
      $parsed = parse_url($url);
      if (is_array($parsed) && isset($parsed['path'])) {
        $url = (string) $parsed['path'];
        if (!empty($parsed['query'])) {
          $url .= '?' . $parsed['query'];
        }
      }
    }

    if ($url === '') {
      return '';
    }

    if ($url[0] !== '/') {
      $url = '/' . $url;
    }

    // Normalize the path portion (preserve query string as-is).
    [$path, $query] = array_pad(explode('?', $url, 2), 2, NULL);
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if ($path !== '/' && str_ends_with($path, '/')) {
      $path = rtrim($path, '/');
    }

    return $query !== NULL && $query !== '' ? $path . '?' . $query : $path;
  }

}
