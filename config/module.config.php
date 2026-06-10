<?php
namespace SampleData;

use Laminas\Router\Http;

return [
    'sample_data' => [
        'datasets' => [
            'artworks' => [
                'label' => 'Artworks',
                'description' => 'Paintings, sculptures, works on paper, and manuscripts spanning art movements from antiquity to the present.',
                'item_count' => 200,
                'set_count' => 5,
            ],
            'civilizations' => [
                'label' => 'Civilizations',
                'description' => 'Historical civilizations, empires, dynasties, and cultural periods from across the ancient, medieval, and early modern world.',
                'item_count' => 450,
                'set_count' => 10,
            ],
            'documents' => [
                'label' => 'Documents',
                'description' => 'Historical handwritten and typed documents including letters, diaries, newspapers, and official records.',
                'item_count' => 50,
                'set_count' => 6,
            ],
            'people' => [
                'label' => 'People',
                'description' => 'Historical figures spanning science, literature, philosophy, exploration, and political leadership across cultures and centuries.',
                'item_count' => 100,
                'set_count' => 5,
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            sprintf('%s/../view', __DIR__),
        ],
    ],
    'controllers' => [
        'factories' => [
            'SampleData\Controller\Admin\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Sample Data',
                'route' => 'admin/sample-data',
                'controller' => 'Index',
                'action' => 'index',
                'resource' => 'SampleData\Controller\Admin\Index',
                'useRouteMatch' => true,
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'sample-data' => [
                        'type' => Http\Literal::class,
                        'options' => [
                            'route' => '/sample-data',
                            'defaults' => [
                                '__NAMESPACE__' => 'SampleData\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
