<?php

namespace Drupal\unl_multisite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;

/**
 * Report of Archive nodes on each site.
 */
class TtpReportController extends ControllerBase {

  public function report() {
    $database_default = Database::getConnection('default');
    $default_database_connection_details = $database_default->getConnectionOptions();
    $default_database_connection_username = $default_database_connection_details['username'];
    $default_database_connection_password = $default_database_connection_details['password'];
    $default_database_connection_driver = $default_database_connection_details['driver'];
    $default_database_connection_host = $default_database_connection_details['host'];

    $site_info = $database_default->query("SELECT site_id, uri FROM {unl_sites} WHERE installed = 2");
    $site_info = $site_info->fetchAll();

    $rows = [];

    foreach ($site_info as $record) {
      $subsite_database_name = 'project-herbie-' . $record->site_id;

      $subsite_database_connection = array(
        'database' => $subsite_database_name,
        'username' => $default_database_connection_username,
        'password' => $default_database_connection_password,
        'host' => $default_database_connection_host,
        'driver' => $default_database_connection_driver,
      );

      Database::addConnectionInfo($subsite_database_name, 'default', $subsite_database_connection);
      $database_connection = Database::getConnection('default', $subsite_database_name);

      $archive_nodes = $database_connection->query("SELECT * FROM {node_field_data} WHERE type = 'archive_page'");
      $archive_nodes = $archive_nodes->fetchAll();

      $published_count = 0;
      $unpublished_count = 0;
      foreach ($archive_nodes as $node) {
        if ($node->status == 1) {
          $published_count++;
        }
        elseif ($node->status == 0) {
          $unpublished_count++;
        }
      }

      $site_info_blob_data = $database_connection->query("SELECT data FROM {config} WHERE name = 'system.site'");
      $site_info_blob_data = $site_info_blob_data->fetchAll();
      $site_info_blob_data = $site_info_blob_data[0]->data;

      if ($site_info_blob_data) {
        $site_data_blob_unseralized = unserialize($site_info_blob_data);
        $site_name = $site_data_blob_unseralized['name'];
      }
      else {
        $site_name = 'Error - site name could not be retrieved';
      }
      $site_uri = $record->uri;

      $unl_system_settings = $database_connection->query("SELECT data FROM {config} WHERE name = 'unl_system.settings'");
      $unl_system_settings = $unl_system_settings->fetchAll();
      $unl_system_settings = unserialize($unl_system_settings[0]->data);
      if (isset($unl_system_settings['primary_base_url']) && !empty($unl_system_settings['primary_base_url'])) {
        $site_uri = $unl_system_settings['primary_base_url'];
      }

      $rows[] = [
        'data' => [
          $this->t($site_name),
          'label' => [
            'data' => [
              'link' => [
                '#type' => 'link',
                '#title' => $this->t($site_uri),
                '#url' => Url::fromUri($site_uri),
              ],
            ],
          ],
          $published_count,
          $unpublished_count,
        ],
      ];
    }

    // Sort the $rows array by $published_count
    usort($rows, function ($a, $b) {
      return $b['data'][1] <=> $a['data'][1]; // Descending order
    });

    // Restore default site database connection.
    Database::setActiveConnection('default');

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Site Name'), $this->t('Site Link'), $this->t('Published TTP pages'), $this->t('Unpublished TTP pages')],
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['dcf-table-bordered', 'dcf-table',  'dcf-table-responsive', 'dcf-ml-auto', 'dcf-mr-auto'],
      ],
    ];

    return $build;
  }
}
