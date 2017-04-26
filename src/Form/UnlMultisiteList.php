<?php

namespace Drupal\unl_multisite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configure book settings for this site.
 */
class UnlMultisiteList extends FormBase {

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
        'field' => 'uri',
      ),
      'name' => array(
        'data' => t('Site name'),
        'field' => 'name',
      ),
      'access' =>  array(
        'data' => t('Last access'),
        'field' => 'access',
      ),
      'installed' => array(
        'data' => t('Status'),
        'field' => 'installed',
      ),
      'operations' => t('Operations'),
    );

    $sites = db_select('unl_sites', 's')
      ->fields('s', array('site_id', 'db_prefix', 'installed', 'site_path'))
      ->execute()
      ->fetchAll();

    // In addition to the above db query, add site name and last access timestamp
    //$this->unl_add_extra_site_info($sites);


    $form['unl_sites'] = array(
      '#caption' => t('Existing Sites: ') . count($sites),
      '#type' => 'table',
      '#header' => $header,
      //'#rows' => $rows,
      '#empty' => t('No sites have been created.'),
    );

    foreach ($sites as $site) {
      $rows[$site->site_id] = array(
        'uri' => array(
          '#type' => 'link',
          '#title' => $site->site_path,
          '#url' => Url::fromUserInput('/' . $site->site_path),
        ),
        'name' => array('#plain_text' => (isset($site->name) ? $site->name : '')),
        'access' => array('#plain_text' => (isset($site->access) ? $site->access : 0)),
        'installed' => array('#plain_text' => $this->_unl_get_install_status_text($site->installed)),
        'operations' => array(
          'data' => array(
            '#type' => 'operations',
            '#links' => array(
              'aliases' => array(
                'title' => t('edit aliases'),
                'url' => Url::fromRoute('unl_multisite.site_list', array()),//'admin/sites/unl/' . $site->site_id . '/aliases',
              ),
              'edit' => array(
                'title' => t('edit site'),
                'url' => Url::fromRoute('unl_multisite.site_list', array()),//'admin/sites/unl/' . $site->site_id . '/edit',
              ),
              'delete' => array(
                'title' => t('delete site'),
                'url' => Url::fromRoute('unl_multisite.site_list', array()),//'admin/sites/unl/' . $site->site_id . '/delete',
                'query' => drupal_get_destination(),
              ),
            ),
          ),
        ),
      );
    }

    // Sort the table data accordingly with a custom sort function
    $order = tablesort_get_order($header);
    $sort = tablesort_get_sort($header);
    $rows = $this->unl_sites_sort($rows, $order, $sort);

    // Now that the access timestamp has been used to sort, convert it to something readable
    foreach ($rows as $key=>$row) {
      $rows[$key]['access'] = array('#plain_text' =>
        isset($row['access']) && $row['access']['#plain_text'] > 0
          ? t('@time ago', array('@time' => \Drupal::service("date.formatter")->formatInterval(REQUEST_TIME - $row['access']['#plain_text'])))
          : t('never')
      );
    }

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
   * @param $sites The result of db_select()->fetchAll() on the unl_sites table.
   */
  function unl_add_extra_site_info($sites) {
    // Get all custom made roles (roles other than authenticated, anonymous, administrator)
    $roles = user_roles(TRUE);
    unset($roles[\Drupal\Core\Session\AccountInterface::AUTHENTICATED_RID]);
    // @FIXME
// // @FIXME
// // This looks like another module's variable. You'll need to rewrite this call
// // to ensure that it uses the correct configuration object.
// unset($roles[variable_get('user_admin_role')]);


    // Setup alternate db connection so we can query other sites' tables without a prefix being attached
    $database_noprefix = array(
      'database' => $GLOBALS['databases']['default']['default']['database'],
      'username' => $GLOBALS['databases']['default']['default']['username'],
      'password' => $GLOBALS['databases']['default']['default']['password'],
      'host' => $GLOBALS['databases']['default']['default']['host'],
      'port' => $GLOBALS['databases']['default']['default']['port'],
      'driver' => $GLOBALS['databases']['default']['default']['driver'],
    );
    Database::addConnectionInfo('UNLNoPrefix', 'default', $database_noprefix);

    // The master prefix that was specified during initial drupal install
    $master_prefix = $GLOBALS['databases']['default']['default']['prefix'];

    foreach ($sites as $row) {
      // Skip over any sites that aren't properly installed.
      if (!in_array($row->installed, array(2, 6))) {
        continue;
      }

      // Switch to alt db connection
      db_set_active('UNLNoPrefix');

      // Get site name
      $table = $row->db_prefix.'_'.$master_prefix.'variable';
      $name = db_query("SELECT value FROM ".$table." WHERE name = 'site_name'")->fetchField();

      // Get last access timestamp (by a non-administrator)
      $table_users = $row->db_prefix.'_'.$master_prefix.'users u';
      $table_users_roles = $row->db_prefix.'_'.$master_prefix.'users_roles r';
      if (!empty($roles)) {
        $access = db_query('SELECT u.access FROM '.$table_users.', '.$table_users_roles.' WHERE u.uid = r.uid AND u.access > 0 AND r.rid IN (' . implode(',', array_keys($roles)) . ') ORDER BY u.access DESC')->fetchColumn();
      }
      else {
        $access = 0;
      }

      // Restore default db connection
      db_set_active();

      // Update unl_sites table of the default site
      $row->name = @unserialize($name);
      $row->access = (int)$access;
    }
  }

  /**
   * Custom sort the Existing Sites table.
   */
  private function unl_sites_sort($rows, $order, $sort) {
    switch ($order['sql']) {
      case 'uri':
        if ($sort == 'asc') {
          usort($rows, function ($a, $b) {return strcasecmp($a['uri']['#title'], $b['uri']['#title']);});
        }
        else {
          usort($rows, function ($a, $b) {return strcasecmp($b['uri']['#title'], $a['uri']['#title']);});
        }
        break;
      case 'name':
        if ($sort == 'asc') {
          usort($rows, function ($a, $b) {return strcasecmp($a['name'], $b['name']);});
        }
        else {
          usort($rows, function ($a, $b) {return strcasecmp($b['name'], $a['name']);});
        }
        break;
      case 'access':
        if ($sort == 'asc') {
          usort($rows, function ($a, $b) {return strcmp($b['access'], $a['access']);});
        }
        else {
          usort($rows, function ($a, $b) {return strcmp($a['access'], $b['access']);});
        }
        break;
      case 'last_update':
        if ($sort == 'asc') {
          usort($rows, function ($a, $b) {return strcmp($b['last_update'], $a['last_update']);});
        }
        else {
          usort($rows, function ($a, $b) {return strcmp($a['last_update'], $b['last_update']);});
        }
        break;
      case 'installed':
        if ($sort == 'asc') {
          usort($rows, function ($a, $b) {return strcmp($a['installed'], $b['installed']);});
        }
        else {
          usort($rows, function ($a, $b) {return strcmp($b['installed'], $a['installed']);});
        }
        break;
    }
    return $rows;
  }

  function _unl_get_install_status_text($id) {
    switch ($id) {
      case 0:
        $installed = t('Scheduled for creation.');
        break;
      case 1:
        $installed = t('Curently being created.');
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
