<?php
namespace SampleData\Job;

use Omeka\Api\Exception\NotFoundException;
use Omeka\Job\AbstractJob;
use Throwable;

/**
 * Base class for Sample Data jobs providing shared service access and cleanup helpers.
 */
abstract class AbstractSampleDataJob extends AbstractJob
{
    protected function get(string $serviceName)
    {
        return $this->getServiceLocator()->get($serviceName);
    }

    protected function clearPendingJob(string $dataset): void
    {
        $this->get('Omeka\Settings')->delete("sample_data_job_{$dataset}");
    }

    /**
     * @param array $tracking Keys: items (int[]), item_sets (array<string,int> keyed by set slug), resource_template (?int)
     */
    protected function purgeDataset(array $tracking): void
    {
        $api = $this->get('Omeka\ApiManager');
        $logger = $this->get('Omeka\Logger');

        $itemIds = $tracking['items'] ?? [];
        $itemSetIds = $tracking['item_sets'] ?? [];
        $templateId = $tracking['resource_template'] ?? null;

        if ($itemIds) {
            try {
                $api->batchDelete('items', $itemIds);
                $logger->info(sprintf('Deleted %d items.', count($itemIds)));
            } catch (Throwable $e) {
                $logger->warn(sprintf('Could not batch delete items: %s', $e->getMessage()));
            }
        }
        if ($itemSetIds) {
            try {
                $api->batchDelete('item_sets', $itemSetIds);
                $logger->info(sprintf('Deleted %d item sets.', count($itemSetIds)));
            } catch (Throwable $e) {
                $logger->warn(sprintf('Could not batch delete item sets: %s', $e->getMessage()));
            }
        }
        if ($templateId) {
            try {
                $api->delete('resource_templates', $templateId);
                $logger->info('Deleted resource template.');
            } catch (NotFoundException $e) {
                $logger->info('Resource template already gone.');
            } catch (Throwable $e) {
                $logger->warn(sprintf('Could not delete resource template: %s', $e->getMessage()));
            }
        }
    }
}
