<?php

namespace Drupal\custom_config\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Commands\config\ConfigImportCommands;
use Drush\Commands\core\UpdateDBCommands;
use Drush\Drush;

/**
 * Comandi Drush del modulo Custom config.
 */
class CustomConfigCommands extends DrushCommands {

  /**
   * Evita di ripetere l'allineamento se il comando viene annidato.
   *
   * @var bool
   */
  protected $applied = FALSE;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
   *   The module extension list.
   */
  public function __construct(ModuleExtensionList $module_extension_list) {
    parent::__construct();
    $this->moduleExtensionList = $module_extension_list;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('extension.list.module')
    );
  }

  /**
   * Sottomoduli con una propria config/update, nell'ordine in cui applicarla.
   *
   * Vengono dopo custom_config, così le loro versioni prevalgono: per esempio
   * custom_config_keryx porta il form del documento con i campi di keryx, che
   * nei siti senza Albo e AT non esistono.
   */
  const SOTTOMODULI = ['custom_config_keryx'];

  /**
   * Riapplica config/update dopo ogni "drush updb".
   *
   * Esegue lo stesso comando che si lancerebbe a mano:
   * @code
   * drush -y config:import --partial --source=.../custom_config/config/update
   * @endcode
   * e poi lo stesso per ogni sottomodulo attivo di self::SOTTOMODULI.
   * A differenza di hook_post_update_NAME(), che core esegue una sola volta per
   * sito, questo hook parte a ogni esecuzione di updatedb, anche quando non ci
   * sono aggiornamenti in sospeso.
   *
   * @param mixed $result
   *   Il risultato del comando updatedb.
   * @param \Consolidation\AnnotatedCommand\CommandData $command_data
   *   I dati del comando in esecuzione.
   */
  #[CLI\Hook(type: HookManager::POST_COMMAND_HOOK, target: UpdateDBCommands::UPDATEDB)]
  public function reapplyCustomConfig($result, CommandData $command_data): void {
    if ($this->applied) {
      return;
    }
    $this->applied = TRUE;

    $moduli = ['custom_config'];
    foreach (self::SOTTOMODULI as $sottomodulo) {
      if (\Drupal::moduleHandler()->moduleExists($sottomodulo)) {
        $moduli[] = $sottomodulo;
      }
    }

    foreach ($moduli as $modulo) {
      $this->riapplica($modulo);
    }
  }

  /**
   * Importa la config/update di un modulo.
   *
   * @param string $modulo
   *   custom_config o uno dei suoi sottomoduli.
   */
  protected function riapplica(string $modulo): void {
    $source = DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath($modulo) . '/config/update';
    // Senza file da importare config:import uscirebbe in errore.
    if (!is_dir($source) || !glob($source . '/*.yml')) {
      $this->logger()->notice(dt('@modulo: nessuna configurazione da riapplicare (config/update vuota).', ['@modulo' => $modulo]));
      return;
    }

    $this->logger()->notice(dt('@modulo: riapplico le configurazioni da @source', [
      '@modulo' => $modulo,
      '@source' => $source,
    ]));

    $process = $this->processManager()->drush(
      Drush::aliasManager()->getSelf(),
      ConfigImportCommands::IMPORT,
      [],
      ['partial' => TRUE, 'source' => $source, 'yes' => TRUE]
    );

    // Volutamente run() e non mustRun(): config:import valida le dipendenze e
    // fallisce, per esempio, se una configurazione cita un modulo non
    // installato su questa scuola. Con mustRun() l'errore interromperebbe
    // l'intero "drush updb"; così l'aggiornamento arriva in fondo e il
    // problema resta ben visibile nel log.
    $process->run();
    $this->output()->writeln($process->getOutput());

    if (!$process->isSuccessful()) {
      $this->logger()->error(dt("@modulo: l'allineamento di config/update è fallito. @error", [
        '@modulo' => $modulo,
        '@error' => $process->getErrorOutput(),
      ]));
      return;
    }

    $this->logger()->success(dt('@modulo: configurazioni di config/update riapplicate.', ['@modulo' => $modulo]));
  }

}