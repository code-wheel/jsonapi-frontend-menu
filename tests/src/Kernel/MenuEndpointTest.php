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
