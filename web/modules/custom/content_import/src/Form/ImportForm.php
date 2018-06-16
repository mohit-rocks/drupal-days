<?php

namespace Drupal\content_import\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\Entity\File;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\language\ConfigurableLanguageManager;

/**
 * Class ImportForm.
 */
class ImportForm extends ConfigFormBase {

  /**
   * Drupal\language\ConfigurableLanguageManager definition.
   *
   * @var \Drupal\language\ConfigurableLanguageManager
   */
  protected $languageManager;

  /**
   * The processed items for one batch of a given migration.
   *
   * @var int
   */
  protected static $numProcessed = 0;

  /**
   * Constructs a new ImportForm object.
   */
  public function __construct(ConfigFactoryInterface $config_factory,
                              ConfigurableLanguageManager $language_manager) {
    parent::__construct($config_factory);
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'content_import.import',
      'migrate_plus.migration.node_product',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('content_import.import');
    $form['products_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Products CSV'),
      '#description' => $this->t('Upload CSV file for importing products'),
      '#upload_location' => 'public://',
      '#default_value' => $config->get('products_csv'),
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
      '#required' => TRUE,
    ];
    $form['language'] = [
      '#type' => 'language_select',
      '#languages' => LanguageInterface::STATE_CONFIGURABLE,
      '#title' => $this->t('Destination Language'),
      '#description' => $this->t('Select the language for the imported products.'),
      '#default_value' => $config->get('language'),
    ];

    $form['actions']['submit']['#value'] = $this->t('Import content');

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
    parent::submitForm($form, $form_state);

    $this->config('content_import.import')
      ->set('products_csv', $form_state->getValue('products_csv'))
      ->set('language', $form_state->getValue('language'))
      ->save();

    // Saving products csv file programmatically.
    // So that we can retrieve file in future.
    $products_csv = $form_state->getValue('products_csv');
    $file = File::load($products_csv[0]);
    // Set the status flag permanent of the file object.
    if (!empty($file)) {
      $file->setPermanent();
      // Save the file in the database.
      $file->save();
    }
    Cache::invalidateTags(['migration_plugins']);

    $language = $form_state->getValue('language');

    $batch = [
      'title' => $this->t('Running import'),
      'progress_message' => $this->t('Content import is in progress'),
      'operations' => [
        [
          [ImportForm::class, 'run'],
          [$language],
        ],
      ],
      'finished' => [
        ImportForm::class, 'finished',
      ],
    ];
    batch_set($batch);
  }

  /**
   * Processing product import from CSV files.
   *
   * @param string $language
   *   Selected language in import form.
   * @param array $context
   *   Context variable for storing batch related variables.
   *
   * @throws
   */
  public static function run($language, &$context) {
    $context['sandbox']['messages'] = [];
    $context['results']['failures'] = 0;
    $context['results']['successes'] = 0;

    if ($language == 'en') {
      $migration = \Drupal::service('plugin.manager.migration')->createInstance('node_product');
    }
    else {
      $migration = \Drupal::service('plugin.manager.migration')->createInstance('node_product_translation:' . $language);
    }


    // Check if migration exists and not running. If it is running then we
    // should stop migration, reset migration and rollback migration.
    // So next migration can be imported successfully.
    if ($migration) {
      $status = $migration->getStatus();
      switch ($status) {
        case MigrationInterface::STATUS_IDLE:
          break;
        case MigrationInterface::STATUS_DISABLED:
          break;
        case MigrationInterface::STATUS_STOPPING:
          break;
        default:
          // Interrupt migration to stop it.
          $migration->interruptMigration(MigrationInterface::RESULT_STOPPED);
          \Drupal::logger('content_import')->notice('@migration is explicitly stopped from content_import module.', [
            '@migration' => $migration->id(),
          ]);

          // Reset migration so it can start process again.
          $migration->setStatus(MigrationInterface::STATUS_IDLE);
          \Drupal::logger('content_import')->notice('@migration is explicitly set to idle from content_import module. It is set to idle, so we can import content again.', [
            '@migration' => $migration->id(),
          ]);
          break;
      }
    }

    $migration->getIdMap()->prepareUpdate();
    $executable = new MigrateExecutable($migration, new MigrateMessage());
    $migration_status = $executable->import();
    $migration_name = 'node_product:' . $language;

    switch ($migration_status) {
      case MigrationInterface::RESULT_COMPLETED:
        // Store the number processed in the sandbox.
        $context['sandbox']['num_processed'] += static::$numProcessed;
        $message = new PluralTranslatableMarkup(
          $context['sandbox']['num_processed'], 'Upgraded @migration (processed 1 item total)', 'Upgraded @migration (processed @count items total)',
          ['@migration' => $migration_name]);
        $context['sandbox']['messages'][] = (string) $message;
        $context['sandbox']['num_processed'] = 0;
        $context['results']['successes']++;
        break;

      case MigrationInterface::RESULT_INCOMPLETE:
        $context['sandbox']['messages'][] = (string) new PluralTranslatableMarkup(
          static::$numProcessed, 'Continuing with @migration (processed 1 item)', 'Continuing with @migration (processed @count items)',
          ['@migration' => $migration_name]);
        $context['sandbox']['num_processed'] += static::$numProcessed;
        break;

      case MigrationInterface::RESULT_STOPPED:
        $context['sandbox']['messages'][] = (string) new TranslatableMarkup('Operation stopped by request');
        break;

      case MigrationInterface::RESULT_FAILED:
        $context['sandbox']['messages'][] = (string) new TranslatableMarkup('Operation on @migration failed', ['@migration' => $migration_name]);
        $context['results']['failures']++;
        \Drupal::logger('bcontent_import')->error('Operation on @migration failed', ['@migration' => $migration_name]);
        break;

      case MigrationInterface::RESULT_SKIPPED:
        $context['sandbox']['messages'][] = (string) new TranslatableMarkup('Operation on @migration skipped due to unfulfilled dependencies', ['@migration' => $migration_name]);
        \Drupal::logger('content_import')->error('Operation on @migration skipped due to unfulfilled dependencies', ['@migration' => $migration_name]);
        break;

      case MigrationInterface::RESULT_DISABLED:
        // Skip silently if disabled.
        break;
    }
  }

  /**
   * Finish callback for batch operation.
   */
  public static function finished($success, $results, $operations, $elapsed) {
    $successes = $results['successes'];
    $failures = $results['failures'];

    // If we had any successes log that for the user.
    if ($successes > 0) {
      drupal_set_message(\Drupal::translation()
        ->formatPlural($successes, 'Completed 1 import task successfully', 'Completed @count import tasks successfully'));
    }
    // If we had failures, log them and show the migration failed.
    if ($failures > 0) {
      drupal_set_message(\Drupal::translation()
        ->formatPlural($failures, '1 upgrade failed', '@count content import failed'));
      drupal_set_message(t('Content import not completed'), 'error');
    }
    else {
      drupal_set_message(t('Content imported successfully.'));
    }
  }

}
