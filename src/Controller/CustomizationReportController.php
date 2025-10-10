<?php

namespace Drupal\unl_multisite\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;

/**
 * Report of customizations on each site.
 */
class CustomizationReportController extends ControllerBase {

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

      // Content Types.
      $node_types = $database_connection->query("SELECT * FROM {config} WHERE name LIKE 'node.type.%'");
      $node_types = $node_types->fetchAll();
      $custom_content_types_count = count($node_types) - 8;

      // HTML blocks.
      $query = <<<EOT
        WITH RECURSIVE block_positions AS (
          -- initial pass: find first occurrence position of the key in each current node revision's blob
          SELECT
            lbl.entity_id,
            lbl.revision_id,
            lbl.layout_builder__layout_section AS txt,
            LOCATE(:search_string, lbl.layout_builder__layout_section) AS pos
          FROM node__layout_builder__layout AS lbl
          JOIN node_field_data AS nfd
            ON nfd.nid = lbl.entity_id
            AND nfd.vid = lbl.revision_id          -- ensures current node revision only
          WHERE lbl.layout_builder__layout_section LIKE '%inline_block:html_code%'  -- cheap prefilter
          UNION ALL
          -- recursive: find next occurrence after the previous position
          SELECT
            bp.entity_id,
            bp.revision_id,
            bp.txt,
            LOCATE(:search_string, bp.txt, bp.pos + 1)
          FROM block_positions bp
          WHERE bp.pos > 0
        ),
        block_refs AS (
          -- extract the numeric value that follows the matched key at each position
          SELECT
            entity_id,
            revision_id,
            CAST(
              SUBSTRING(
                txt,
                LOCATE(':"', txt, pos + CHAR_LENGTH(:search_string)) + 2,
                LOCATE('"', txt, LOCATE(':"', txt, pos + CHAR_LENGTH(:search_string)) + 2)
                  - (LOCATE(':"', txt, pos + CHAR_LENGTH(:search_string)) + 2)
              ) AS UNSIGNED
            ) AS block_revision_id
          FROM block_positions
          WHERE pos > 0
        )
        -- final: join the found revision ids to block_content_revision and type-check to html_code
        SELECT COUNT(DISTINCT br.block_revision_id) AS html_code_block_count
        FROM block_refs br
        JOIN block_content_revision bcr ON bcr.revision_id = br.block_revision_id
        JOIN block_content_field_data bfd    ON bcr.id = bfd.id
        WHERE bfd.type = 'html_code'
        EOT;

      $html_blocks = $database_connection->query($query, [':search_string' => '"block_revision_id";s:']);
      // The above query excludes blocks in old revisions and only counts "live"
      //   blocks. A much simpler (lol) query will get the count but it includes
      //   all blocks that exist including ones that have been removed from the
      //   current revision of a Builder page node:
      //   $html_blocks = $database_connection->query("SELECT * FROM {block_content__b_html_code_html}");
      $html_blocks = $html_blocks->fetchAll();
      $html_blocks = $html_blocks[0]->html_code_block_count;

      // Site name.
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

      // Site URL.
      $site_uri = $record->uri;
      $unl_system_settings = $database_connection->query("SELECT data FROM {config} WHERE name = 'unl_system.settings'");
      $unl_system_settings = $unl_system_settings->fetchAll();
      $unl_system_settings = unserialize($unl_system_settings[0]->data);
      if (isset($unl_system_settings['primary_base_url']) && !empty($unl_system_settings['primary_base_url'])) {
        $site_uri = $unl_system_settings['primary_base_url'];
      }

      // Build rows.
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
          $custom_content_types_count,
          $html_blocks,
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
      '#header' => [
        $this->t('Site Name'),
        $this->t('Site Link'),
        $this->t('Custom content types'),
        $this->t('HTML blocks')],
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['dcf-table-bordered', 'dcf-table',  'dcf-table-responsive', 'dcf-ml-auto', 'dcf-mr-auto'],
      ],
    ];

    return $build;
  }
}
