<?php

namespace Drupal\custom_config_keryx\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Impedisce l'indicizzazione dei file privati allegati agli atti dell'albo.
 *
 * Un PDF non ha un <head> in cui mettere il meta robots: per i file serviti
 * da Drupal (schema private://) si usa l'intestazione HTTP X-Robots-Tag.
 */
class AlboRobotsSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse']];
  }

  /**
   * Aggiunge X-Robots-Tag ai download dei file allegati agli atti dell'albo.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if (!in_array($route, ['system.files', 'system.private_file_download'], TRUE) || !$request->query->has('file')) {
      return;
    }

    $scheme = $request->attributes->get('scheme', 'private');
    $uri = $scheme . '://' . $request->query->get('file');

    $nids = $this->database->select('file_usage', 'u')
      ->fields('u', ['id'])
      ->condition('u.type', 'node')
      ->condition('f.uri', $uri);
    $nids->join('file_managed', 'f', 'f.fid = u.fid');
    $nids = $nids->execute()->fetchCol();
    if (!$nids) {
      return;
    }

    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface && _custom_config_keryx_is_atto_albo($node)) {
        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return;
      }
    }
  }

}
