<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Dashboard\Widget;

use Formatsoft\ContentReminder\Dashboard\ReminderOverviewProvider;
use Formatsoft\ContentReminder\Service\JavaScriptLabels;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * List of reminders, configured via options (Configuration/Services.php):
 * - scope: open | due | overdue
 * - assignee: me | unassigned | any
 * - showAssignee: bool
 * - limit: int
 */
final class ReminderListWidget implements WidgetInterface, RequestAwareWidgetInterface, JavaScriptInterface, AdditionalCssInterface
{
    private ?ServerRequestInterface $request = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly ReminderOverviewProvider $provider,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly JavaScriptLabels $javaScriptLabels,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:content_reminder/Resources/Private/Templates'],
            request: $this->request,
        ));
        $view->assignMultiple([
            'configuration' => $this->configuration,
            'items' => $this->provider->getItems(
                match ($this->options['scope'] ?? 'open') {
                    'due' => 'due',
                    'overdue' => 'overdue',
                    default => 'open',
                },
                match ($this->options['assignee'] ?? 'any') {
                    'me' => 'me',
                    'unassigned' => 'unassigned',
                    default => 'any',
                },
                (int)($this->options['limit'] ?? 20)
            ),
            'showAssignee' => (bool)($this->options['showAssignee'] ?? false),
            'emptyLabel' => (string)($this->options['emptyLabel'] ?? 'widget.empty'),
            'labels' => $this->javaScriptLabels->toJson($GLOBALS['LANG']),
        ]);
        return $view->render('Widget/ReminderList');
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getJavaScriptModuleInstructions(): array
    {
        return [JavaScriptModuleInstruction::create('@formatsoft/content-reminder/reminder-actions.js')];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:content_reminder/Resources/Public/Css/backend.css'];
    }
}
