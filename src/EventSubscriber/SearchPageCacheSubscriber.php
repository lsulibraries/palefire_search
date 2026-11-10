<?php

namespace Drupal\palefire_search\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Has browsers check back for the search page on every visit.
 *
 * The site lets browsers keep pages for a day. A results page kept that long
 * would still show, and load sections from, the code before a deploy. Drupal's
 * own page cache still answers, quickly, and a cache clear empties it, so a
 * deploy shows at once. Late sections and holdings are versioned instead (see
 * SearchController::sections()).
 */
class SearchPageCacheSubscriber implements EventSubscriberInterface {

  /**
   * Sets the search page's browser caching.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $event->getRequest()->attributes->get('_route') !== 'palefire_search.page') {
      return;
    }
    $response = $event->getResponse();
    if ($response->headers->hasCacheControlDirective('public')) {
      $response->setMaxAge(0);
      $response->headers->addCacheControlDirective('must-revalidate');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After core's FinishResponseSubscriber (0), which sets the site's max-age.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

}
