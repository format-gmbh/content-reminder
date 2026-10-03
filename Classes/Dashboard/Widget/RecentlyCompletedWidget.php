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
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * Latest entries of the archive: who completed what and when (option: limit).
 */
final class RecentlyCompletedWidget implements WidgetInterface, RequestAwareWidgetInterface, AdditionalCssInterface
{
    private ?ServerRequestInterface $request = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly ReminderOverviewProvider $provider,
        private readonly ViewFactoryInterface $viewFactory,
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
        $sysConf = $GLOBALS['TYPO3_CONF_VARS']['SYS'];
        $view->assignMultiple([
            'configuration' => $this->configuration,
            'entries' => $this->provider->getRecentlyCompleted((int)($this->options['limit'] ?? 15)),
            'dateTimeFormat' => ($sysConf['ddmmyy'] ?? 'd.m.Y') . ' ' . ($sysConf['hhmm'] ?? 'H:i'),
        ]);
        return $view->render('Widget/RecentlyCompleted');
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getCssFiles(): array
    {
        return ['EXT:content_reminder/Resources/Public/Css/backend.css'];
    }
}
