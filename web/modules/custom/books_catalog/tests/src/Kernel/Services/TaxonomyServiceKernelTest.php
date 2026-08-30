<?php

declare(strict_types=1);

namespace Drupal\Tests\books_catalog\Kernel\Services;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Kernel tests for the taxonomy upsert service.
 *
 * @group books_catalog
 * @coversDefaultClass \Drupal\books_catalog\Services\TaxonomyService
 */
class TaxonomyServiceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'taxonomy',
    'field',
    'text',
    'user',
    'books_catalog',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\books_catalog\Services\TaxonomyService
   */
  protected $taxonomy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'field']);
    Vocabulary::create(['vid' => 'publisher', 'name' => 'Publisher'])->save();
    $this->taxonomy = $this->container->get('books_catalog.taxonomy');
  }

  /**
   * Tests that a term is created once and reused afterwards.
   *
   * @covers ::getTermByName
   */
  public function testGetTermByNameCreatesThenLoads(): void {
    $term = $this->taxonomy->getTermByName('Penguin Books', 'publisher');
    $this->assertNotNull($term);
    $this->assertSame('Penguin Books', $term->label());

    $again = $this->taxonomy->getTermByName('Penguin Books', 'publisher');
    $this->assertSame($term->id(), $again->id());
  }

  /**
   * Tests that an empty name yields no term.
   *
   * @covers ::getTermByName
   */
  public function testGetTermByNameEmptyReturnsNull(): void {
    $this->assertNull($this->taxonomy->getTermByName('', 'publisher'));
  }

}
