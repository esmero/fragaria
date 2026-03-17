<?php

namespace Drupal\fragaria\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use LimitIterator;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;

/**
 * Form controller Data Cite File Based Reports.
 *
 * @ingroup ami
 */
class FragariaDataCiteReportForm extends FormBase {

  /**
   * @var
   */
  private CONST LOG_LEVELS = [
    'INFO'      => 'INFO',
    'ERROR'   => 'ERROR',
  ];

  public function getFormId() {
    return 'fragaria_report_form';
  }

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @param \Drupal\Component\Datetime\TimeInterface|NULL $time
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   */
  public function __construct(
    TimeInterface $time = NULL, FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('datetime.time'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Read Config first to get the Selected Bundles based on the Config
    // type selected. Based on that we can set Moderation Options here

    $data = new \stdClass();

    /* Fetch the status from the private store also: */

    $num_per_page = 25;
    $logfilename_error = "private://fragaria/logs/datacite.log";
    $logfilename_info = "private://fragaria/logs/datacite_peppermint.log";
    $level = $this->getRequest()->get('logs', 'ERROR');
    $level = $form_state->getValue(['logs','level']) ?? $level;
    $level =  in_array($level,array_keys(static::LOG_LEVELS)) ? $level : 'ERROR';
    if ($level == "ERROR") {
      $logfilename = $this->fileSystem->realpath($logfilename_error);
    }
    else {
      $logfilename = $this->fileSystem->realpath($logfilename_info);
    }
    if ($logfilename !== FALSE && file_exists($logfilename)) {
      clearstatcache(TRUE, $logfilename);
      // How many lines?
      $file = new \SplFileObject($logfilename, 'r');
      $file->seek(PHP_INT_MAX);
      $total_lines = $file->key(); // last line number

      // Initialize Pager based on the total here
      $pager = \Drupal::service('pager.manager')->createPager(
        $total_lines, $num_per_page
      );
      /* @var $pager \Drupal\Core\Pager\Pager */
      $page = $pager->getCurrentPage();
      $page = $page + 1;

      // Won't override $total_lines because i still need this for the offset
      // Independently of the level selection.
      $total_lines_for_pager = $total_lines;
      $total_lines_current = 0;

      $rows = [];
      $offset = $total_lines - ($num_per_page * $page);
      $num_per_page = $offset < 0 ? $num_per_page + $offset : $num_per_page;
      $offset = $offset < 0 ? 0 : $offset;
      $fetch = TRUE;
      // This only RUNS if all is selected.
      // ROWS have been already fetched for the other levels before.
      while ($offset >= 0 && count($rows) < $num_per_page) {
        $reader = new LimitIterator($file, $offset, $num_per_page);
        foreach ($reader as $line) {
          $currentLineExpanded = json_decode($line, TRUE);
          $row = [];
          $fetch = TRUE;
          if (json_last_error() == JSON_ERROR_NONE) {
            $row['datetime'] = $currentLineExpanded['datetime'];
            $row['level'] = $currentLineExpanded['level_name'];
            $row['message'] = $this->t($currentLineExpanded['message'], []);
            $row['details'] = json_encode($currentLineExpanded['context']);
            $rows[] = $row;
          }
          else {
            // Only show wrongly formatter if no filter present.
            $row = ['Wrong Format for this entry', '', '', $line];
            $rows[] = $row;
          }
          if (count($rows) ==  $num_per_page) { break;}
        }
        $rows = array_reverse($rows);
      }
      $file = NULL;

      $message = $this->t(
        'You have @count entries for your current Filter',
        [
          '@count' => $total_lines_for_pager,
          '@date'  => !empty($timestamp) ? date(
            'D, d M Y \a\t H:i:s', $timestamp
          ) : " Unknown ",
        ]
      );
      $form['logs'] = [
        '#tree'   => TRUE,
        '#type'   => 'fieldset',
        '#prefix' => '<div id="edit-log">',
        '#suffix' => '</div>',
        '#title'  => $this->t(
          'Info'
        ),
        '#markup' => $message,
        'level'   => [
          '#type'          => 'select',
          '#options'       => static::LOG_LEVELS,
          '#default_value' => $level ,
          '#title' => $this->t('Filter by log level:'),
          '#submit' => ['::submitForm'],
          '#ajax' => [
            'callback' => '::myAjaxCallback',
            'disable-refocus' => FALSE,
            'event' => 'change',
            'wrapper' => 'edit-log',
            'progress' => [
              'type' => 'throbber',
              'message' => $this->t('Filtering Logs...'),
            ],
          ],
        ],
        'logs'    => [
          '#type'   => 'table',
          '#header' => [
            $this->t('datetime'),
            $this->t('level'),
            $this->t('message'),
            $this->t('details'),
          ],
          '#rows'   => $rows,
          '#sticky' => TRUE,
        ]
      ];

      $form['logs']['pager'] = [
        '#type' => 'pager',
        '#prefix' => '<div id="edit-log-pager">',
        '#suffix' => '</div>',
        '#parameters' => ['level' => $level]
      ];

    }
    else {
      $form['logs'] = [
        '#tree'   => TRUE,
        '#type'   => 'fieldset',
        '#title'  => $this->t(
          'Info'
        ),
        '#markup' => $this->t(
          'No Logs Found'
        ),
      ];
    }

    // Add a submit button that handles the submission of the form.
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#attributes' => [
        'class' => ['js-hide'],
      ],
    );

    // Because this form has no real submissions and the entity itself is not changing
    // we had users seen stale (no reports) but other user loging in can see them
    // Maybe this helps?
    $form['#cache'] = [
      'max-age' => 0
    ];
    return $form;
  }

  public function myAjaxCallback(array &$form, FormStateInterface $form_state) {
    foreach ([
      AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER,
      FormBuilderInterface::AJAX_FORM_REQUEST,
      MainContentViewSubscriber::WRAPPER_FORMAT,
    ] as $key) {
      if ($this->getRequest()) {
        $this->getRequest()->query->remove($key);
        $this->getRequest()->request->remove($key);
      }
    }
    return $form['logs'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
  }

}

