<?php

namespace Drupal\unl_multisite\Cron;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\layout_builder\Section;
use Psr\Log\LoggerInterface;

/**
 * Handles background updates for multisite data.
 */
class MultisiteCron {

  protected $db;
  protected $cache;
  protected $logger;

  public function __construct(Connection $db, CacheBackendInterface $cache, LoggerInterface $logger) {
    $this->db = $db;
    $this->cache = $cache;
    $this->logger = $logger;
  }

  public function run($manual = FALSE) {
    $batch_size = $manual ? 200 : 100; // Larger batch for manual refresh
    $processed = 0;

    $cached = $this->cache->get('unl_multisite.data');
    $data = $cached && is_array($cached->data) ? $cached->data : [];

    $offset = $data['last_offset'] ?? 0;

    $query = $this->db->select('unl_sites', 's')
      ->fields('s', ['site_id', 'site_path', 'installed'])
      ->condition('installed', 2)
      ->range($offset, $batch_size);
    $sites = $query->execute()->fetchAllAssoc('site_id');

    if (empty($sites)) {
      $offset = 0;
      $query = $this->db->select('unl_sites', 's')
        ->fields('s', ['site_id', 'site_path', 'installed'])
        ->condition('installed', 2)
        ->range($offset, $batch_size);
      $sites = $query->execute()->fetchAllAssoc('site_id');
    }

    foreach ($sites as $site_id => $site) {
      $db_name = "project-herbie-" . $site_id;
      try {
        $target = "unl_site_{$site_id}";
        $connection_info = $this->db->getConnectionOptions();
        $connection_info['database'] = $db_name;
        Database::addConnectionInfo($target, 'default', $connection_info);
        $conn = Database::getConnection('default', $target);

        // Get site name.
        $site_name = $this->getSiteName($conn);

        // Get Primary Base URL.
        $primary_base_url = $this->getPrimaryBaseUrl($conn);

        // Get custom content types count.
        $content_type_count = $this->getContentTypeCount($conn);
        if ($content_type_count > 0) {
          $content_type_count = $content_type_count - 8;
        }

        // Get HTML Code block count.
        $html_block_count = $this->getBlockContentCount($conn, 'html_code');

        // Get Twig UI template count.
        $twig_ui_template_count = $this->getTwigTemplateCount($conn);

        // Get Asset Injector counts.
        $asset_injector_css_count = $this->getAssetInjectorCount($conn, 'css');
        $asset_injector_js_count = $this->getAssetInjectorCount($conn, 'js');

        $data[$site_id] = [
          'site_id' => $site_id,
          'site_path' => $site->site_path,
          'site_name' => $site_name,
          'primary_base_url' => $primary_base_url,
          'html_block_count' => $html_block_count,
          'content_type_count' => $content_type_count,
          'twig_ui_template_count' => $twig_ui_template_count,
          'asset_injector_css_count' => $asset_injector_css_count,
          'asset_injector_js_count' => $asset_injector_js_count,
          'timestamp' => \Drupal::time()->getRequestTime(),
        ];
        $processed++;
      }
      catch (\Exception $e) {
        $this->logger->warning("Failed to fetch site info for site @id: @message", [
          '@id' => $site_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $data['last_offset'] = $offset + $batch_size;
    $this->cache->set('unl_multisite.data', $data);

    return $processed;
  }

  public function getSiteName($conn) {
    $site_name_config = $conn->select('config', 'c')
      ->fields('c', ['data'])
      ->condition('c.name', 'system.site')
      ->execute()
      ->fetchField();
    $site_data = @unserialize($site_name_config);

    return $site_data['name'] ?? '(Unknown)';
  }

  public function getPrimaryBaseURL($conn) {
    $unl_system_settings = $conn->query("SELECT data FROM {config} WHERE name = 'unl_system.settings'");
    $unl_system_settings = $unl_system_settings->fetchAll();
    $unl_system_settings = unserialize($unl_system_settings[0]->data);
    $primary_base_url = 'Not set';
    if (isset($unl_system_settings['primary_base_url']) && !empty($unl_system_settings['primary_base_url'])) {
      $primary_base_url = $unl_system_settings['primary_base_url'];
    }

    return $primary_base_url;
  }

  public function getContentTypeCount($conn) {
    $content_type_count = $conn->select('config', 'c')
      ->condition('name', 'node.type.%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $content_type_count;
  }

  public function getBlockContentCount($conn, $block_type) {
    // Query nodes with Layout Builder data.
    $query = $conn->select('node__layout_builder__layout', 'lbl')
      ->fields('lbl', [
        'entity_id',
        'revision_id',
        'layout_builder__layout_section'
      ])
      ->condition('lbl.bundle', 'builder_page'); // Restrict to nodes.

    // Join with node_field_data to ensure nodes are published.
    // $query->join('node_field_data', 'nfd', 'nfd.nid = lbl.entity_id');
    // $query->condition('nfd.status', 1); // Only published nodes.

    // Join with node_revision to ensure latest revision.
    // $query->join('node_revision', 'nr', 'nr.nid = nfd.nid AND nr.vid = nfd.vid');

    // Join with node_revision__layout_builder__layout to get revision data.
    // $query->join('node_revision__layout_builder__layout', 'lbr', 'lbr.revision_id = nr.vid AND lbr.entity_id = lbl.entity_id');

    $results = $query->execute()->fetchAll();

    // Parse Layout Builder sections and count block instances.
    $count = 0;
    foreach ($results as $result) {
      // Unserialize the layout_builder__layout_section data into a Section object.
      $section = unserialize($result->layout_builder__layout_section);
      if (!$section instanceof Section) {
        continue;
      }
      // Iterate through each component in the section.
      foreach ($section->getComponents() as $component) {
        if ($component->getPluginId() == 'inline_block:' . $block_type) {
          $count++;
        }
      }
    }

    return $count;
  }

  public function getTwigTemplateCount($conn) {
    $twig_ui_template_count = $conn->select('config', 'c')
      ->condition('name', 'twig_ui.template.%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $twig_ui_template_count;
  }

  public function getAssetInjectorCount($conn, $type = 'css') {
    $asset_injector_count = $conn->select('config', 'c')
      ->condition('name', 'asset_injector.' . $type . '.%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $asset_injector_count;
  }
}
