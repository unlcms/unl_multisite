<?php
/**
 * @file
 * Contains \Drupal\unl_multisite\Controller\UnlMultisiteController.
 */

namespace Drupal\unl_multisite\Controller;

use Drupal\Core\Controller\ControllerBase;
use \Drupal\Core\Database\Database;
use Drupal\Core\Url;

/**
 * Controller routines for unl_multisite routes.
 */
class UnlMultisiteController extends ControllerBase {

/**
 * Returns an administrative overview of all sites.
 *
 * @return array
 *   A render array representing the administrative page content.
 */
  public function XXsitesOverview() {

    $build = array(
      '#type' => 'markup',
      '#markup' => t('Hello World!'),
    );
    return $build;
  }

  public function unl_multisite_my_sites()
  {

    $database_default = [];

    $current_loggedin_user = \Drupal::currentUser();
    $uid = $current_loggedin_user->id();

    $database_default = Database::getConnection('default');
    $default_database_connection_details = $database_default->getConnectionOptions();
    $default_database_connection_username = $default_database_connection_details['username'];
    $default_database_connection_password = $default_database_connection_details['password'];
    $default_database_connection_driver = $default_database_connection_details['driver'];
    $default_database_connection_host = $default_database_connection_details['host'];

    $site_info = $database_default->query("select site_id, uri from {unl_sites}");
    $site_info = $site_info->fetchAll();

    $rows = [];

    foreach ($site_info as $record) {
      $site_id =  $record->site_id;
      $sub_site_database = 'project-herbie-' . $site_id;

      $subsite_database_connection = array(
        'database' => $sub_site_database,
        'username' => $default_database_connection_username,
        'password' => $default_database_connection_password,
        'host' => $default_database_connection_host,
        'driver' => $default_database_connection_driver,
      );

      Database::addConnectionInfo($sub_site_database, 'default', $subsite_database_connection);
      $database_connection = Database::getConnection('default', $sub_site_database);
      $subsite_user_roles = $database_connection->query("select roles_target_id from {user__roles} where [entity_id] = :entity_id", [':entity_id' => $uid]);
      $subsite_user_roles = $subsite_user_roles->fetchAll();

      if ($subsite_user_roles) {

        $list_roles = function($record)  {
          switch ($record->roles_target_id) {
            case 'viewer':
              $role = 'Viewer';
              break;

            case 'editor':
              $role = 'Editor';
              break;

            case 'site_admin':
              $role = 'Site Admin';
              break;

            case 'coder':
              $role = 'Developer';
              break;

            case 'super_administrator';
              $role = 'Super Administrator';
              break;

            case 'administrator':
              $role = 'Administrator';
              break;

            default:
              $role = 'Error - Undefined Role';
              break;
          }

          return $role;
        };


        $roles = array_map( $list_roles, $subsite_user_roles);

        $subsite_user_roles = implode(', ', $roles);

        $database_connection = Database::getConnection('default', $sub_site_database);

        $site_info_blob_data = $database_connection->query("SELECT data from {config} where name = 'system.site'");
        $site_info_blob_data = $site_info_blob_data->fetchAll();
        $site_info_blob_data = $site_info_blob_data[0]->data;

        if($site_info_blob_data) {
          $site_data_blob_unseralized = unserialize($site_info_blob_data);
          $site_name = $site_data_blob_unseralized['name'];
          
        } else {
          $site_name = 'Error - site name could not be retrieved';
        }
        $site_uri = $record->uri;

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
            ], $this->t($subsite_user_roles),
          ],
        ];
      }
    }
    
    Database::setActiveConnection('default');

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Site Name'), $this->t('Site Link'), $this->t('Role/Roles')],
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['dcf-table-bordered', 'dcf-table',  'dcf-table-responsive', 'dcf-ml-auto', 'dcf-mr-auto'],
      ],
    ];

    return $build;
  }
}
