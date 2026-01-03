<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend_menu\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\system\Entity\Menu;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the menu endpoint.
 *
 * @group jsonapi_frontend_menu
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class MenuEndpointTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'link',
    'node',
    'path',
    'path_alias',
    'menu_link_content',
    'jsonapi',
    'serialization',
    'jsonapi_frontend',
    'jsonapi_frontend_menu',
  ];

  /**
   * Menu link plugin ids created for this test.
   *
   * @var array<string, string>
   */
  private array $ids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['jsonapi_frontend']);

    // Allow anonymous users to access published content, so menu access checks
    // treat node links as accessible.
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    if (!$anonymous) {
      $anonymous = Role::create([
        'id' => RoleInterface::ANONYMOUS_ID,
        'label' => 'Anonymous',
      ]);
    }
    $anonymous->grantPermission('access content');
    $anonymous->save();

    $admin = User::create([
      'name' => 'admin',
      'status' => 1,
    ]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    Menu::create([
      'id' => 'main',
      'label' => 'Main navigation',
    ])->save();

    Menu::create([
      'id' => 'empty',
      'label' => 'Empty menu',
    ])->save();

    Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ])->save();

    Node::create([
      'type' => 'page',
      'title' => 'Team',
      'status' => 1,
      'path' => ['alias' => '/about-us/team'],
    ])->save();

    $parent = MenuLinkContent::create([
      'title' => 'About',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/about-us'],
      'expanded' => TRUE,
    ]);
    $parent->save();

    $parent_id = 'menu_link_content:' . $parent->uuid();
    $this->ids['about'] = $parent_id;

    $child = MenuLinkContent::create([
      'title' => 'Team',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/about-us/team'],
      'parent' => $parent_id,
    ]);
    $child->save();
    $this->ids['team'] = 'menu_link_content:' . $child->uuid();

    $admin_only = MenuLinkContent::create([
      'title' => 'Admin',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/admin'],
    ]);
    $admin_only->save();
    $this->ids['admin'] = 'menu_link_content:' . $admin_only->uuid();

    $external = MenuLinkContent::create([
      'title' => 'External',
      'menu_name' => 'main',
      'link' => ['uri' => 'https://example.com'],
    ]);
    $external->save();
    $this->ids['external'] = 'menu_link_content:' . $external->uuid();

    $login = MenuLinkContent::create([
      'title' => 'Login',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/user/login'],
    ]);
    $login->save();
    $this->ids['login'] = 'menu_link_content:' . $login->uuid();

    $query = MenuLinkContent::create([
      'title' => 'With Query',
      'menu_name' => 'main',
      'link' => [
        'uri' => 'internal:/about-us',
        'options' => [
          'query' => [
            'utm' => '1',
          ],
          'fragment' => 'frag',
        ],
      ],
    ]);
    $query->save();
    $this->ids['query'] = 'menu_link_content:' . $query->uuid();

    $this->config('jsonapi_frontend.settings')
      ->set('drupal_base_url', 'https://cms.example.com')
      ->save();
  }

  public function testMenuActiveTrailAndResolve(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/main', 'GET', [
      '_format' => 'json',
      'path' => '/about-us/team',
    ]);

    $response = $controller->menu($request, 'main');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertEquals('main', $payload['meta']['menu']);

    $trail = $payload['meta']['active_trail'];
    $this->assertEquals([$this->ids['about'], $this->ids['team']], $trail);

    $about = $this->findItemById($payload['data'], $this->ids['about']);
    $team = $this->findItemById($payload['data'], $this->ids['team']);

    $this->assertNotNull($about);
    $this->assertNotNull($team);

    $this->assertFalse($about['active']);
    $this->assertTrue($about['in_active_trail']);
    $this->assertTrue($team['active']);
    $this->assertTrue($team['in_active_trail']);

    $this->assertTrue($about['resolve']['resolved']);
    $this->assertEquals('entity', $about['resolve']['kind']);
    $this->assertNotEmpty($about['resolve']['jsonapi_url']);

    $this->assertTrue($team['resolve']['resolved']);
    $this->assertEquals('entity', $team['resolve']['kind']);
    $this->assertNotEmpty($team['resolve']['jsonapi_url']);

    $admin = $this->findItemById($payload['data'], $this->ids['admin']);
    $this->assertNull($admin);

    $external = $this->findItemById($payload['data'], $this->ids['external']);
    $this->assertNotNull($external);
    $this->assertTrue($external['external']);
    $this->assertNull($external['resolve']);
  }

  public function testMenuNotFoundReturns404(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/does-not-exist', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->menu($request, 'does-not-exist');
    $this->assertSame(404, $response->getStatusCode());
  }

  public function testEmptyMenuReturns200WithNoItems(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/empty', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->menu($request, 'empty');
    $this->assertSame(200, $response->getStatusCode());

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($payload);
    $this->assertSame('empty', $payload['meta']['menu']);
    $this->assertSame([], $payload['data']);
  }

  public function testUnresolvedInternalLinksFallBackToRouteKind(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/main', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->menu($request, 'main');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $login = $this->findItemById($payload['data'], $this->ids['login']);
    $this->assertNotNull($login);

    $this->assertTrue($login['resolve']['resolved']);
    $this->assertSame('route', $login['resolve']['kind']);
    $this->assertStringContainsString('/user/login', (string) $login['resolve']['drupal_url']);
  }

  public function testResolveCanBeDisabled(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/main', 'GET', [
      '_format' => 'json',
      'resolve' => '0',
    ]);

    $response = $controller->menu($request, 'main');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertFalse($payload['meta']['resolve']);

    $about = $this->findItemById($payload['data'], $this->ids['about']);
    $this->assertNotNull($about);
    $this->assertNull($about['resolve']);
  }

  public function testMenuQueryDepthAndParentOptionsAreHandled(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('resolver.cache_max_age', 60)
      ->set('resolver.langcode_fallback', 'current')
      ->save();

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/main', 'GET', [
      '_format' => 'json',
      'path' => 'https://www.example.com/about-us/team?utm=1#frag',
      'min_depth' => '2',
      'max_depth' => '3',
      'parent' => $this->ids['about'],
    ]);

    $response = $controller->menu($request, 'main');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($payload);
    $this->assertSame([$this->ids['about'], $this->ids['team']], $payload['meta']['active_trail']);

    $team = $this->findItemById($payload['data'], $this->ids['team']);
    $this->assertNotNull($team);
  }

  public function testAuthenticatedUserDisablesCaching(): void {
    $this->config('jsonapi_frontend.settings')
      ->set('resolver.cache_max_age', 60)
      ->save();

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/main', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->menu($request, 'main');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  public function testInternalUrlsAreNormalizedForResolve(): void {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    $controller = \Drupal\jsonapi_frontend_menu\Controller\MenuController::create($this->container);
    $request = Request::create('/jsonapi/menu/main', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->menu($request, 'main');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $query = $this->findItemById($payload['data'], $this->ids['query']);
    $this->assertNotNull($query);
    $this->assertSame('/about-us?utm=1', $query['url']);
    $this->assertTrue($query['resolve']['resolved']);
  }

  /**
   * Find an item by id in a nested menu tree.
   *
   * @param array $items
   *   Menu items.
   * @param string $id
   *   Plugin id.
   *
   * @return array|null
   *   The item, or NULL.
   */
  private function findItemById(array $items, string $id): ?array {
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      if (($item['id'] ?? NULL) === $id) {
        return $item;
      }

      if (!empty($item['children']) && is_array($item['children'])) {
        $found = $this->findItemById($item['children'], $id);
        if ($found !== NULL) {
          return $found;
        }
      }
    }

    return NULL;
  }

}
