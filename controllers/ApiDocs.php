<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ApiDocs extends Controller
{
    public $requiredPermissions = [
        'kodzero.posmall.manage_api',
    ];

    private const PUBLIC_REST_DOCS = [
        'REST guide' => 'plugins/kodzero/posmall/docs/api-v1-rest.md',
        'REST contract table' => 'plugins/kodzero/posmall/docs/api-v1-contract.md',
    ];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('KodZero.POSMall', 'posmall-orders', 'posmall-api-docs');
    }

    public function index(): void
    {
        $this->pageTitle = 'POSMall REST API Documentation';
        $this->vars['documents'] = $this->publicRestDocuments();
        $this->vars['openApiPath'] = base_path('plugins/kodzero/posmall/docs/openapi-v1.yaml');
    }

    private function publicRestDocuments(): array
    {
        $documents = [];

        foreach (self::PUBLIC_REST_DOCS as $title => $relativePath) {
            $path = base_path($relativePath);
            $markdown = File::exists($path)
                ? (string)File::get($path)
                : 'Documentation file is missing: `' . $relativePath . '`.';

            $documents[] = [
                'title' => $title,
                'path' => $relativePath,
                'html' => (string)Str::markdown($markdown, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]),
            ];
        }

        return $documents;
    }
}
