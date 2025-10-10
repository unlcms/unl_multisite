<?php

namespace Drupal\unl_multisite\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\unl_multisite\Cron\MultisiteCron;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to manually refresh multisite data.
 */
class CustomizationReportRefreshForm extends FormBase {

  /**
   * @var \Drupal\unl_multisite\Cron\MultisiteCron
   */
  protected $multisiteCron;

  public function __construct(MultisiteCron $multisiteCron) {
    $this->multisiteCron = $multisiteCron;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('unl_multisite.cron')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unl_multisite_refresh_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('<p>Click the button below to refresh a batch of cached multisite data. This may take several seconds. You may have to click several times to refresh everything.</p>'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Refresh cache now'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Run a manual refresh.
    $count = $this->multisiteCron->run(TRUE);
    $this->messenger()->addMessage($this->t('Refreshed data for @count sites.', ['@count' => $count]));
  }

}
