<?php

namespace Drupal\unl_multisite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class UnlMultisiteAliasesCreate extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unl_multisite_site_aliases_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $site_id = NULL) {
    $query = db_select('unl_sites', 's')
      ->fields('s', array('site_id', 'site_path'))
      ->orderBy('uri');
    if (isset($site_id)) {
      $query->condition('site_id', $site_id);
    }
    $sites = $query->execute()->fetchAll();
    foreach ($sites as $site) {
      $site_list[$site->site_id] = $site->site_path;
    }

    $form['site'] = array(
      '#type' => 'select',
      '#title' => t('Aliased site path'),
      '#description' => t('The site the alias will point to.'),
      '#options' => $site_list,
      '#required' => TRUE,
      '#default_value' => (isset($site_id) ? $site_id : FALSE),
      '#disabled' => (isset($site_id) ? TRUE : FALSE),
    );
    $form['base_uri'] = array(
      '#type' => 'textfield',
      '#title' => t('Alias base URL'),
      '#description' => t('The base URL for the new alias. This should resolve to the directory containing the .htaccess file.'),
      '#default_value' => Url::fromRoute('<front>', [], ['https' => FALSE, 'absolute' => TRUE])->toString(),
      '#required' => TRUE,
    );
    $form['path'] = array(
      '#type' => 'textfield',
      '#title' => t('Path'),
      '#description' => t('Path for the new alias.'),
    );
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Create alias'),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $form_state->setValue('base_uri', trim($form_state->getValue('base_uri')));
    $form_state->setValue('path', trim($form_state->getValue('path')));

    if (substr($form_state->getValue('base_uri'), -1) != '/') {
      $form_state->setValue('base_uri', $form_state->getValue('base_uri') . '/');
    }
    if (substr($form_state->getValue('path'), -1) != '/') {
      $form_state->setValue('path', $form_state->getValue('path') . '/');
    }
    if (substr($form_state->getValue('path'), 0, 1) == '/') {
      $form_state->setValue('path', substr($form_state->getValue('path'), 1));
    }

    // Check that the alias does not already exist.
    $query = db_select('unl_sites_aliases', 'a');
    $query->fields('a', array('base_uri', 'path'));

    $db_or = db_or();
    $db_or->condition('a.path', $form_state->getValue('path'), '=');

    // Also consider legacy aliases that do not have a trailing slash.
    $db_or->condition('a.path', substr($form_state->getValue('path'), 0, -1), '=');

    $db_and = db_and();
    $db_and->condition('a.base_uri', $form_state->getValue('base_uri'), '=');
    $db_and->condition($db_or);

    $query->condition($db_and);
    $result = $query->execute()->fetchAssoc();

    if ($result) {
      form_set_error('alias_path', t('Site alias already exists.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    db_insert('unl_sites_aliases')->fields(array(
      'site_id' => $form_state->getValue('site'),
      'base_uri' => $form_state->getValue('base_uri'),
      'path' => $form_state->getValue('path'),
    ))->execute();

    drupal_set_message(t('The site alias has been scheduled for creation. Run unl_multisite/cron.php to finish creation.'));
  }

}
