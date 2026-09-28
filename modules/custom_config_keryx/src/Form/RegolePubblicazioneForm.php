<?php

namespace Drupal\custom_config_keryx\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Regole di pubblicazione dei documenti per tipologia.
 *
 * Le tipologie sono salvate per nome: gli ID dei termini cambiano da un sito
 * all'altro, i nomi della distribuzione no.
 */
class RegolePubblicazioneForm extends ConfigFormBase {

  /**
   * Configurazione del sottomodulo.
   */
  const SETTINGS = 'custom_config_keryx.settings';

  /**
   * Vocabolario delle tipologie di documento.
   */
  const VOCABOLARIO = 'tipologia_documenti';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'custom_config_keryx_regole_pubblicazione';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [static::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::SETTINGS);
    $opzioni = self::tipologie();

    $form['info'] = [
      '#markup' => $this->t('<p>Regole applicate al salvataggio dei documenti, secondo il Regolamento dell\'albo online (art. 4). Le tipologie non più presenti nel vocabolario vengono ignorate.</p>'),
    ];
    $form['tipologie_solo_at'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Solo in Amministrazione Trasparente'),
      '#description' => $this->t('Un documento con queste tipologie deve indicare l\'obbligo di pubblicazione di Amministrazione Trasparente: non va all\'Albo online (es. determine a contrarre, contratti di appalto, bilanci, contrattazione integrativa, regolamenti).'),
      '#options' => $opzioni,
      '#default_value' => array_values(array_intersect($config->get('tipologie_solo_at') ?? [], $opzioni)),
    ];
    $form['tipologie_non_pubblicabili'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Non pubblicabili'),
      '#description' => $this->t('Documenti con dati personali che nessuna norma impone di pubblicare (es. contratti individuali e incarichi nominativi del personale): si possono salvare solo come non pubblicati e restano nell\'albo storico riservato.'),
      '#options' => $opzioni,
      '#default_value' => array_values(array_intersect($config->get('tipologie_non_pubblicabili') ?? [], $opzioni)),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $doppie = array_intersect(array_filter($form_state->getValue('tipologie_solo_at')), array_filter($form_state->getValue('tipologie_non_pubblicabili')));
    if ($doppie) {
      $form_state->setErrorByName('tipologie_non_pubblicabili', $this->t('Una tipologia non può essere in entrambi gli elenchi: @tipologie.', ['@tipologie' => implode(', ', $doppie)]));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(static::SETTINGS)
      ->set('tipologie_solo_at', array_values(array_filter($form_state->getValue('tipologie_solo_at'))))
      ->set('tipologie_non_pubblicabili', array_values(array_filter($form_state->getValue('tipologie_non_pubblicabili'))))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Nomi delle tipologie di documento, indicizzati per nome.
   */
  public static function tipologie(): array {
    $nomi = \Drupal::database()->select('taxonomy_term_field_data', 't')
      ->fields('t', ['name'])
      ->condition('t.vid', static::VOCABOLARIO)
      ->orderBy('t.name')
      ->execute()
      ->fetchCol();
    return array_combine($nomi, $nomi);
  }

}
