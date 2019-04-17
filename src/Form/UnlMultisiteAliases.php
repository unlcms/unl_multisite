<?php

namespace Drupal\unl_multisite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class UnlMultisiteAliases extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unl_multisite_site_aliases';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $site_id = NULL) {
    $header = array(
      'site_uri' => array(
        'data' => t('Aliased site path'),
        'field' => 's.uri',
      ),
      'alias_uri' => array(
        'data' => t('Alias URI'),
        'field' => 'a.path',
      ),
      'installed' => array(
        'data' => t('Status'),
        'field' => 'a.installed',
      ),
      'remove' => t('Remove'),
    );

    $query = db_select('unl_sites_aliases', 'a')
      ->extend('Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header);
    $query->join('unl_sites', 's', 's.site_id = a.site_id');
    if (isset($site_id)) {
      $query->condition('s.site_id', $site_id);
    }
    $query->fields('s', array('site_path'));
    $query->fields('a', array('site_alias_id', 'base_uri', 'path', 'installed'));
    $sites = $query->execute()->fetchAll();


    $form['alias_list'] = array(
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t('No aliases available.'),
    );

    foreach ($sites as $site) {
      $options[$site->site_alias_id] = array(
        'site_uri' => array('#prefix' => $site->site_path),
        'alias_uri' => array('#prefix' => $site->base_uri . '<span style="color:#777">' . $site->path . '</span>'),
        'installed' => array('#prefix' => UnlMultisiteList::_unl_get_install_status_text($site->installed)),
        'remove' => array(
          '#type' => 'checkbox',
          '#parents' => array('aliases', $site->site_alias_id, 'remove'),
          '#default_value' => 0,
          '#disabled' => $site->installed == 6,
        ),
      );
    }

    foreach ($options as $key => $row) {
      $form['alias_list'][$key] = $row;
    }

    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Delete selected aliases'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    $site_alias_ids = array(-1);
    foreach ($values['aliases'] as $site_alias_id => $alias) {
      if ($alias['remove']) {
        $site_alias_ids[] = $site_alias_id;
      }
    }

    $query = db_select('unl_sites_aliases', 'a');
    $query->join('unl_sites', 's', 'a.site_id = s.site_id');
    $data = $query
      ->fields('a', array('site_alias_id', 'base_uri', 'path'))
      ->condition('site_alias_id', $site_alias_ids, 'IN')
      ->execute()
      ->fetchAll();

    $site_alias_ids = array(-1);
    foreach ($data as $row) {
      $alias_url = $row->base_uri . $row->path;
      $site_alias_ids[] = $row->site_alias_id;
      drupal_set_message("The alias $alias_url was scheduled for removal.");
    }

    db_update('unl_sites_aliases')
      ->fields(array('installed' => 3))
      ->condition('site_alias_id', $site_alias_ids, 'IN')
      ->execute();
  }

}
