<?php

namespace Drupal\unl_multisite\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\TableSort;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * List of multisite installations.
 */
class UnlMultisiteList extends FormBase {

  /**
   * Base database API.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $databaseConnection;

  /**
   * Request represents an HTTP request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Class constructor for form object.
   *
   * @param \Drupal\Core\Database\Connection $database_connection
   *   Base database API.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Request stack that controls the lifecycle of requests.
   */
  public function __construct(Connection $database_connection, RequestStack $request) {
    $this->databaseConnection = $database_connection;
    $this->request = $request->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unl_multisite_site_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $header = array(
      'uri' => array(
        'data' => t('Default path'),
        'field' => 'site_path',
      ),
      'name' => array(
        'data' => t('Site name'),
        'field' => 'name',
      ),
      'id' => array(
        'data' => t('Site ID'),
        'field' => 'site_id',
      ),
      'primary_base_url' => array(
        'data' => t('Primary base url'),
        'field' => 'primary_base_url',
      ),
      'd7_site_id' => array(
        'data' => t('D7 site ID'),
        'field' => 'd7_site_id',
      ),
      'access' =>  array(
        'data' => t('Last access'),
        'field' => 'access',
      ),
      'last_edit' =>  array(
        'data' => t('Last edit'),
        'field' => 'last_edit',
      ),
      'site_admin' =>  array(
        'data' => t('Site admin'),
        'field' => 'site_admin',
      ),
      'installed' => array(
        'data' => t('Status'),
        'field' => 'installed',
      ),
      'operations' => t('Operations'),
    );

    $site_count = $this->databaseConnection->select('unl_sites', 's')
      ->fields('s', array('site_id'))
      ->execute()
      ->fetchAll();

    $sites = $this->databaseConnection->select('unl_sites', 's')
      ->extend(PagerSelectExtender::class)->limit(200)
      ->fields('s', array('site_id', 'd7_site_id', 'site_path', 'uri', 'installed'))
      ->orderBy('s.site_path', 'ASC')
      ->execute()
      ->fetchAll();

    // In addition to the above db query, add site name and last access timestamp
    $this->unl_add_extra_site_info($sites);

    $form['unl_sites'] = array(
      '#caption' => t('Existing Sites: ') . count($site_count),
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t('No sites have been created.'),
    );
    $form['pager'] = array(
      '#type' => 'pager',
    );

    $rows = [];
    foreach ($sites as $site) {
      $rows[$site->site_id] = array(
        'uri' => array(
          '#type' => 'link',
          '#title' => $site->site_path,
          '#url' => Url::fromUserInput('/' . $site->site_path),
        ),
        'name' => array('#plain_text' => (isset($site->name) ? $site->name : '')),
        'site_id' => array('#plain_text' => (isset($site->site_id) ? $site->site_id : null)),
        'primary_base_url' => array('#plain_text' => (!empty($site->primary_base_url) ? $site->primary_base_url : 'Not set')),
        'd7_site_id' => array('#plain_text' => (isset($site->d7_site_id) ? $site->d7_site_id : 'Not set')),
        'access' => array('#plain_text' => (isset($site->access) ? $site->access : '')),
        'last_edit' => array('#plain_text' => (isset($site->last_edit) ? $site->last_edit : '')),
        'site_admin' => array('#markup' => (isset($site->site_admin) ? $site->site_admin : 'No Site admin')),
        'installed' => array('#plain_text' => $this->_unl_get_install_status_text($site->installed)),
        'operations' => array(
          'data' => array(
            '#type' => 'operations',
            '#links' => array(
              'aliases_create' => array(
                'title' => t('create alias'),
                'url' => Url::fromRoute('unl_multisite.site_aliases_create', ['site_id' => $site->site_id]),
              ),
              'aliases' => array(
                'title' => t('view aliases'),
                'url' => Url::fromRoute('unl_multisite.site_aliases', ['site_id' => $site->site_id]),
              ),
              'edit' => array(
                'title' => t('edit site'),
                'url' => Url::fromRoute('unl_multisite.site_edit', ['site_id' => $site->site_id]),//'admin/sites/unl/' . $site->site_id . '/edit',
              ),
              'delete' => array(
                'title' => t('delete site'),
                'url' => Url::fromRoute('unl_multisite.site_delete', ['site_id' => $site->site_id]),
              ),
            ),
          ),
        ),
      );
    }

    // Sort the table data accordingly with a custom sort function
    $order = TableSort::getOrder($header, $this->request);
    $sort = TableSort::getsort($header, $this->request);
    $rows = $this->unl_sites_sort($rows, $order, $sort);
    // Now that the access timestamp has been used to sort, convert it to something readable
//        foreach ($rows as $key=>$row) {
//          $rows[$key]['access'] = array('#plain_text' =>
//            isset($row['access']) && $row['access']['#plain_text'] > 0
//              ? t('@time ago', array('@time' => \Drupal::service("date.formatter")->formatInterval(REQUEST_TIME - $row['access']['#plain_text'])))
//              : t('never')
//          );
//        }

    foreach ($rows as $key => $row) {
      $form['unl_sites'][$key] = $row;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    return;
  }

  /**
   * Adds virtual name and access fields to a result set from the unl_sites table.
   * @param $sites The result of $this->databaseConnection->select()->fetchAll() on the unl_sites table.
   */
  function unl_add_extra_site_info(&$sites) {
    $database_default = [];

    $database_default = Database::getConnection('default');
    $default_database_connection_details = $database_default->getConnectionOptions();
    $default_database_connection_username = $default_database_connection_details['username'];
    $default_database_connection_password = $default_database_connection_details['password'];
    $default_database_connection_driver = $default_database_connection_details['driver'];
    $default_database_connection_host = $default_database_connection_details['host'];

    foreach ($sites as &$row) {
      // Skip over any sites that aren't properly installed.
      if (!in_array($row->installed, array(2, 6))) {
        continue;
      }

      $site_id = $row->site_id;
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

      $site_info_blob_data = $database_connection->query("SELECT data FROM {config} WHERE name = 'system.site'");
      $site_info_blob_data = $site_info_blob_data->fetchAll();
      $site_info_blob_data = $site_info_blob_data[0]->data;

      if ($site_info_blob_data) {
        $site_data_blob_unseralized = unserialize($site_info_blob_data);
        $name = $site_data_blob_unseralized['name'];
      } else {
        $name = 'Error - site name could not be retrieved';
      }

      $unl_settings_blob_data = $database_connection->query("SELECT data FROM {config} WHERE name = 'unl_system.settings'");
      $unl_settings_blob_data = $unl_settings_blob_data->fetchAll();
      // Check if system site configuration settings has been visited for the site
      if ($unl_settings_blob_data) {
        $unl_settings_blob_data = $unl_settings_blob_data[0]->data;
        if ($unl_settings_blob_data) {
          $unl_settings_data_blob_unseralized = unserialize($unl_settings_blob_data);
          if(isset($unl_settings_data_blob_unseralized['primary_base_url'])) {
            $primary_base_url = $unl_settings_data_blob_unseralized['primary_base_url'];
          }
        } 
        else {
          $primary_base_url = 'Error - primary base url could not be retrieved';
        }
      } 
      else {
        $primary_base_url = 'Not set';
      }
      // Retrieve the last accessed date by a Site Admin.
      $access = $database_connection->query("SELECT FROM_UNIXTIME(MAX(u.access), '%Y-%m-%d') FROM {users_field_data} u, {user__roles} r WHERE u.uid = r.entity_id AND u.access > 0 AND r.roles_target_id = 'site_admin' ORDER BY u.access DESC");
      $access  = $access->fetchField();

      //Retrieve the last edited node date.
      $site_last_edit = $database_connection->query("SELECT FROM_UNIXTIME(MAX(changed), '%Y-%m-%d') AS most_recent_node_update FROM node_field_data");
      $site_last_edit  = $site_last_edit->fetchField();

      // Retrieve the site's users with the site_admin role.
      $site_admin_list = null;
      $site_admins = $database_connection->query("SELECT u.name FROM {users_field_data} u, {user__roles} r WHERE u.uid = r.entity_id AND r.roles_target_id = 'site_admin' ORDER BY u.name ASC");
      $site_admins = $site_admins->fetchAllAssoc('name');
      foreach ($site_admins as $site_admin) {
        $site_admin_list .= $site_admin->name . '<br>';
      }

      $row->primary_base_url = $primary_base_url;
      $row->name = $name;
      $row->access = $access;
      $row->last_edit = $site_last_edit;
      $row->site_admin = $site_admin_list;
    }
    Database::setActiveConnection('default');
  }

  /**
   * Custom sort the Existing Sites table.
   */
  private function unl_sites_sort($rows, $order, $sort) {
    switch ($order['sql']) {
      case 'site_path':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strcasecmp($first_comparing_value['uri']['#title'], $second_comparing_value['uri']['#title']);});
        }
        else {
          uasort($rows, function ($second_comparing_value, $first_comparing_value) {return strcasecmp($first_comparing_value['uri']['#title'], $second_comparing_value['uri']['#title']);});
        }
        break;
      case 'primary_base_url':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strcasecmp($first_comparing_value['primary_base_url']['#plain_text'], $second_comparing_value['primary_base_url']['#plain_text']);});
        }
        else {
          uasort($rows, function ($second_comparing_value, $first_comparing_value) {return strcasecmp($first_comparing_value['primary_base_url']['#plain_text'], $second_comparing_value['primary_base_url']['#plain_text']);});
        }
        break;
      case 'name':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strcasecmp($first_comparing_value['name']['#plain_text'], $second_comparing_value['name']['#plain_text']);});
        }
        else {
          uasort($rows, function ($second_comparing_value, $first_comparing_value) {return strcasecmp($first_comparing_value['name']['#plain_text'], $second_comparing_value['name']['#plain_text']);});
        }
        break;
      case 'access':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return $first_comparing_value['access']['#plain_text'] - $second_comparing_value['access']['#plain_text'];});
        }
        else {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return $second_comparing_value['access']['#plain_text']  - $first_comparing_value['access']['#plain_text'];});
        }
        break;
      case 'last_edit':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strtotime($first_comparing_value['last_edit']['#plain_text']) - strtotime($second_comparing_value['last_edit']['#plain_text']);});
        }
        else {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strtotime($second_comparing_value['last_edit']['#plain_text'])  - strtotime($first_comparing_value['last_edit']['#plain_text']);});
        }
          break;
      case 'site_id':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return $first_comparing_value['site_id']['#plain_text'] - $second_comparing_value['site_id']['#plain_text'];});
        }
        else {
          uasort($rows, function ($second_comparing_value, $first_comparing_value, ) {return $second_comparing_value['site_id']['#plain_text'] - $first_comparing_value['site_id']['#plain_text'];});
        }
        break;
      case 'd7_site_id':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strnatcmp($first_comparing_value['d7_site_id']['#plain_text'], $second_comparing_value['d7_site_id']['#plain_text']);});
        }
        else {
          uasort($rows, function ($second_comparing_value, $first_comparing_value) {return  strnatcmp($first_comparing_value['d7_site_id']['#plain_text'], $second_comparing_value['d7_site_id']['#plain_text']);});
        }
        break;
      case 'installed':
        if ($sort == 'asc') {
          uasort($rows, function ($first_comparing_value, $second_comparing_value) {return strnatcmp($first_comparing_value['installed']['#plain_text']->jsonSerialize(), $second_comparing_value['installed']['#plain_text']->jsonSerialize());});
        }
        else {
          uasort($rows, function ($second_comparing_value, $first_comparing_value) {return strnatcmp($first_comparing_value['installed']['#plain_text']->jsonSerialize(), $second_comparing_value['installed']['#plain_text']->jsonSerialize());});
        }
        break;
    }
    return $rows;
  }

  public static function _unl_get_install_status_text($id) {
    switch ($id) {
      case 0:
        $installed = t('Scheduled for creation.');
        break;
      case 1:
        $installed = t('Currently being created.');
        break;
      case 2:
        $installed = t('In production.');
        break;
      case 3:
        $installed = t('Scheduled for removal.');
        break;
      case 4:
        $installed = t('Currently being removed.');
        break;
      case 5:
        $installed = t('Failure/Unknown.');
        break;
      case 6:
        $installed = t('Scheduled for site update.');
        break;
      default:
        $installed = t('Unknown');
        break;
    }
    return $installed;
  }
}
