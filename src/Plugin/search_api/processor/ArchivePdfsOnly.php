<?php

namespace Drupal\palefire_search\Plugin\search_api\processor;

use Drupal\node\NodeInterface;
use Drupal\palefire_search\SearchSources;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Indexes only the archive records Combined Search shows.
 *
 * Finding aids and oral histories are searched through their PDFs. The rest,
 * such as Breaux Data, were most of the index and never shown. A content type
 * can be left out in the index's settings, but not a record type within one.
 *
 * @SearchApiProcessor(
 *   id = "palefire_search_archive_pdfs",
 *   label = @Translation("Archive records: finding aids and oral histories only"),
 *   description = @Translation("Leaves out archive records Combined Search never shows, such as Breaux Data. Other content types are unaffected."),
 *   stages = {
 *     "alter_items" = 0,
 *   },
 * )
 */
class ArchivePdfsOnly extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    return in_array('entity:node', $index->getDatasourceIds(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {
    // Each archive record type a source searches: both PDF Finding Aids' and
    // Oral histories'.
    $kept = [];
    foreach ($items as $id => $item) {
      $node = $item->getOriginalObject()?->getValue();
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $kept[$node->bundle()] ??= SearchSources::recordTypes($node->bundle());
      foreach ($kept[$node->bundle()] as $field => $types) {
        if ($node->hasField($field) && !in_array((string) $node->get($field)->value, $types, TRUE)) {
          unset($items[$id]);
        }
      }
    }
  }

}
