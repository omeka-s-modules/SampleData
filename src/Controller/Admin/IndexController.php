<?php
namespace SampleData\Controller\Admin;

use Exception;
use Laminas\Form\Element\Csrf;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use SampleData\Job\Import;
use SampleData\Job\Purge;

class IndexController extends AbstractActionController
{
    private array $datasets;

    private const JOBS = [
        'import' => [
            'class' => Import::class,
            'message' => 'Importing the %s dataset.', // @translate
        ],
        'purge' => [
            'class' => Purge::class,
            'message' => 'Purging the %s dataset.', // @translate
        ],
    ];

    public function __construct(array $datasets)
    {
        $this->datasets = $datasets;
    }

    public function indexAction()
    {
        if ($this->getRequest()->isPost()) {
            $csrf = new Csrf('sample_data_csrf');
            if (!$csrf->getCsrfValidator()->isValid($this->params()->fromPost('csrf', ''))) {
                $this->messenger()->addError('Invalid or expired security token. Please try again.');
                return $this->redirect()->toRoute('admin/sample-data');
            }

            $dataset = $this->params()->fromPost('dataset');
            $action = $this->params()->fromPost('action');

            if (!isset($this->datasets[$dataset]) || !isset(self::JOBS[$action])) {
                return $this->redirect()->toRoute('admin/sample-data');
            }

            $args = ['dataset' => $dataset];
            if ($action === 'import') {
                $args['media_base_url'] = sprintf(
                    '%smodules/SampleData/datasets/',
                    $this->url()->fromRoute('top', [], ['force_canonical' => true])
                );
            }

            $job = $this->jobDispatcher()->dispatch(self::JOBS[$action]['class'], $args);

            $this->settings()->set("sample_data_job_{$dataset}", [
                'job_id' => $job->getId(),
                'action' => $action,
            ]);

            $this->messenger()->addSuccess(sprintf(
                self::JOBS[$action]['message'],
                $this->datasets[$dataset]['label']
            ));

            return $this->redirect()->toRoute('admin/sample-data');
        }

        $settings = $this->settings();
        $datasets = [];

        foreach ($this->datasets as $name => $config) {
            $tracking = $settings->get("sample_data_imported_{$name}");
            $datasets[$name] = $config + [
                'name'             => $name,
                'imported'         => (bool) $tracking,
                'main_item_set_id' => $tracking['item_sets']['main'] ?? null,
                'item_set_ids'     => $tracking ? array_values($tracking['item_sets']) : [],
                'pending_action'   => null,
                'job_failed'       => false,
            ];

            $pendingJob = $settings->get("sample_data_job_{$name}");
            if (!$pendingJob) {
                continue;
            }
            $jobId = $pendingJob['job_id'];
            // Once failed, skip re-querying — the error state persists until the user acts.
            if (!empty($pendingJob['failed'])) {
                $datasets[$name]['job_failed'] = true;
                $datasets[$name]['pending_job_id'] = $jobId;
                continue;
            }
            try {
                $job = $this->api()->read('jobs', $jobId)->getContent();
                $status = $job->status();
                if (in_array($status, ['starting', 'in_progress'])) {
                    $datasets[$name]['pending_action'] = $pendingJob['action'];
                    $datasets[$name]['pending_job_id'] = $jobId;
                } elseif (in_array($status, ['error', 'stopped'])) {
                    $datasets[$name]['job_failed'] = true;
                    $datasets[$name]['pending_job_id'] = $jobId;
                    $pendingJob['failed'] = true;
                    $settings->set("sample_data_job_{$name}", $pendingJob);
                } else {
                    $settings->delete("sample_data_job_{$name}");
                }
            } catch (Exception $e) {
                // Job record unreadable (likely deleted); remove the stale tracking entry.
                $settings->delete("sample_data_job_{$name}");
            }
        }

        return new ViewModel(['datasets' => $datasets]);
    }
}
