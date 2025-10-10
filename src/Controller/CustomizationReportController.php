<?php

namespace Drupal\unl_multisite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CustomizationReportController extends ControllerBase {

  protected $cache;
  protected $formBuilder;
  protected $database;

  public function __construct(CacheBackendInterface $cache, FormBuilderInterface $form_builder, Connection $database) {
    $this->cache = $cache;
    $this->formBuilder = $form_builder;
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.default'),
      $container->get('form_builder'),
      $container->get('database')
    );
  }

  public function report() {
    // Build the refresh button form.
    $form = $this->formBuilder->getForm('\Drupal\unl_multisite\Form\CustomizationReportRefreshForm');

    // Table header.
    $header = [
      ['data' => $this->t('Site ID')],
      ['data' => $this->t('Default Path')],
      ['data' => $this->t('Site Name')],
      ['data' => $this->t('Domain')],
      ['data' => $this->t('HTML Blocks')],
      ['data' => $this->t('Content Types')],
      ['data' => $this->t('Twig Templates')],
      ['data' => $this->t('CSS')],
      ['data' => $this->t('JS')],
      ['data' => $this->t('Last Data Fetch')],
    ];

    // Load cached data (from cron or manual refresh).
    $cached = $this->cache->get('unl_multisite.data');
    $cached_data = ($cached && is_array($cached->data)) ? $cached->data : [];

    // Always get all sites from unl_sites.
    $sites = $this->database->select('unl_sites', 's')
      ->fields('s', ['site_id', 'site_path', 'installed'])
      ->orderBy('site_id')
      ->execute()
      ->fetchAllAssoc('site_id');

    $rows = [];

    foreach ($sites as $site_id => $site) {
      $info = $cached_data[$site_id] ?? NULL;

      $rows[] = [
        'data' => [
          $site->site_id,
          ['data' => ['#markup' => '<a href="' . $site->site_path . '">' . $site->site_path . '</a>']],
          $info['site_name'] ?? $this->t('(Not yet fetched)'),
          ['data' => ['#markup' => ($info['primary_base_url'] === 'Not set' ? $info['primary_base_url'] : '<a href="' . $info['primary_base_url'] . '">' . $info['primary_base_url'] . '</a>')]],
          $info['html_block_count'] ?? $this->t('–'),
          $info['content_type_count'] ?? $this->t('–'),
          $info['twig_ui_template_count'] ?? $this->t('–'),
          $info['asset_injector_css_count'] ?? $this->t('–'),
          $info['asset_injector_js_count'] ?? $this->t('–'),
          isset($info['timestamp'])
            ? \Drupal::service('date.formatter')->format($info['timestamp'], 'short')
            : $this->t('(Pending)'),
        ],
      ];
    }

    if (empty($rows)) {
      $rows[] = [['data' => $this->t('No sites found in unl_sites.')]];
    }

    return [
      $form,
      [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No sites found.'),
      ],
    ];
  }
}
