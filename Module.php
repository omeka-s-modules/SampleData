<?php
namespace SampleData;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    private const VOCAB_NS_URI = 'https://omeka.org/s/vocabs/sample-data#';

    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }

    public function install(ServiceLocatorInterface $services)
    {
        $api = $services->get('Omeka\ApiManager');
        $existing = $api->search('vocabularies', [
            'namespace_uri' => self::VOCAB_NS_URI,
            'limit' => 0,
        ]);
        if (0 === $existing->getTotalResults()) {
            $services->get('Omeka\RdfImporter')->import(
                'file',
                [
                    'o:namespace_uri' => self::VOCAB_NS_URI,
                    'o:prefix' => 'sample-data',
                    'o:label' => 'Sample Data',
                    'o:comment' => 'Shared vocabulary for the Sample Data module.',
                ],
                [
                    'file' => $this->vocabFile(),
                    'format' => 'turtle',
                ]
            );
        } else {
            $this->syncVocab($services);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        // syncVocab is safe to run on every upgrade: it adds new terms from the
        // N3 file but never deletes existing ones, so it cannot break items
        // that already use the vocabulary.
        $this->syncVocab($services);
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $settings = $services->get('Omeka\Settings');
        $config = $services->get('Config');
        foreach (array_keys($config['sample_data']['datasets']) as $dataset) {
            $settings->delete("sample_data_imported_{$dataset}");
            $settings->delete("sample_data_job_{$dataset}");
        }
    }

    private function syncVocab(ServiceLocatorInterface $services): void
    {
        $rdfImporter = $services->get('Omeka\RdfImporter');
        $vocabs = $services->get('Omeka\ApiManager')->search('vocabularies', [
            'namespace_uri' => self::VOCAB_NS_URI,
            'limit' => 1,
        ])->getContent();
        if (!$vocabs) {
            return;
        }
        $diff = $rdfImporter->getDiff('file', self::VOCAB_NS_URI, [
            'file' => $this->vocabFile(),
            'format' => 'turtle',
        ]);
        $rdfImporter->update($vocabs[0]->id(), $diff);
    }

    private function vocabFile(): string
    {
        return sprintf('%s/vocabs/sample-data.n3', __DIR__);
    }
}
