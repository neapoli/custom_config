<?php

namespace Drupal\custom_config_keryx\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;

/**
 * Annullamento di un atto dell'Albo online (Regolamento dell'albo, art. 8).
 *
 * L'atto non si cancella: resta pubblicato fino alla scadenza con la dicitura
 * "[ANNULLATO]" nel titolo e un riquadro che riporta data, operatore e
 * motivazione. L'operatore è l'utente collegato, non un valore digitato.
 */
class AnnullaAttoForm extends FormBase {

  /**
   * Prefisso del titolo degli atti annullati.
   */
  const PREFISSO = '[ANNULLATO] ';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'custom_config_keryx_annulla_atto';
  }

  /**
   * Accesso: permesso dedicato, solo atti dell'albo non ancora annullati.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(
      _custom_config_keryx_is_atto_albo($node)
      && !str_starts_with($node->label(), self::PREFISSO)
      && $account->hasPermission('annulla atti albo')
    )->cachePerPermissions()->addCacheableDependency($node);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('node', $node);

    $form['avviso'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p>Stai annullando l\'atto <strong>@titolo</strong> (registro n. @registro).</p><p>L\'atto <strong>non viene cancellato</strong>: resta pubblicato fino alla scadenza con la dicitura "ANNULLATO", la data, il tuo nome come operatore e la motivazione. L\'operazione non si può annullare.</p>', [
        '@titolo' => $node->label(),
        '@registro' => $node->get(CUSTOM_CONFIG_KERYX_REGISTRO_FIELD)->value ?: '-',
      ]),
    ];
    $form['motivazione'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Motivazione dell\'annullamento'),
      '#required' => TRUE,
      '#rows' => 4,
      '#description' => $this->t('Sarà pubblicata insieme all\'atto: non inserire dati personali non necessari.'),
    ];
    $form['rimozione_immediata'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Rimuovere subito l\'atto dalla consultazione pubblica'),
      '#description' => $this->t('Solo se l\'annullamento è dovuto a una violazione di legge o a un ordine dell\'autorità competente. L\'atto resterà nell\'albo storico.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Annulla l\'atto'),
      '#button_type' => 'danger',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Torna all\'atto'),
      '#url' => $node->toUrl(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_state->get('node');
    $motivazione = trim($form_state->getValue('motivazione'));
    $operatore = self::nomeOperatore($this->currentUser());
    $data = (new DrupalDateTime('now'))->format('d/m/Y');

    $riquadro = Paragraph::create([
      'type' => 'callout',
      'field_callout_display' => 'standard',
      'field_callout_variant' => 'danger',
      'field_title' => 'Atto annullato',
      'field_text' => [
        'value' => '<p>' . $this->t('Annullato il @data da @operatore.', ['@data' => $data, '@operatore' => $operatore]) . '</p><p>' . $this->t('Motivazione:') . ' ' . nl2br(htmlspecialchars($motivazione, ENT_QUOTES, 'UTF-8')) . '</p>',
        'format' => 'bootstrap_italia_2',
      ],
    ]);
    $riquadro->save();

    // Il riquadro va in testa alle "Ulteriori informazioni".
    $extra = $node->get('field_extra_info')->getValue();
    array_unshift($extra, ['target_id' => $riquadro->id(), 'target_revision_id' => $riquadro->getRevisionId()]);
    $node->set('field_extra_info', $extra);
    $node->setTitle(self::PREFISSO . $node->label());

    $rimozione = (bool) $form_state->getValue('rimozione_immediata');
    if ($rimozione) {
      $node->setUnpublished();
    }

    $node->setNewRevision(TRUE);
    $node->setRevisionUserId($this->currentUser()->id());
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage(sprintf('Atto annullato il %s da %s%s. Motivazione: %s', $data, $operatore, $rimozione ? ', con rimozione immediata dalla consultazione pubblica' : '', $motivazione));
    $node->save();

    \Drupal::logger('custom_config_keryx')->notice('Atto @nid (registro @registro) annullato da @operatore (uid @uid)@rimozione. Motivazione: @motivazione', [
      '@nid' => $node->id(),
      '@registro' => $node->get(CUSTOM_CONFIG_KERYX_REGISTRO_FIELD)->value ?: '-',
      '@operatore' => $operatore,
      '@uid' => $this->currentUser()->id(),
      '@rimozione' => $rimozione ? ', con rimozione immediata' : '',
      '@motivazione' => $motivazione,
    ]);

    $this->messenger()->addStatus($this->t('L\'atto è stato annullato.'));
    $form_state->setRedirectUrl($node->toUrl());
  }

  /**
   * Nome e cognome dell'operatore, dai campi del profilo se presenti.
   */
  public static function nomeOperatore(AccountInterface $account): string {
    $user = User::load($account->id());
    if ($user && $user->hasField('field_nome') && $user->hasField('field_cognome')) {
      $nome = trim($user->get('field_nome')->value . ' ' . $user->get('field_cognome')->value);
      if ($nome !== '') {
        return $nome;
      }
    }
    return $account->getDisplayName();
  }

}
