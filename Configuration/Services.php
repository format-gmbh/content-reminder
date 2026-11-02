<?php

declare(strict_types=1);

use Formatsoft\ContentReminder\Dashboard\Provider\DueForUserCountProvider;
use Formatsoft\ContentReminder\Dashboard\Provider\StatusChartProvider;
use Formatsoft\ContentReminder\Dashboard\Widget\RecentlyCompletedWidget;
use Formatsoft\ContentReminder\Dashboard\Widget\ReminderListWidget;
use Formatsoft\ContentReminder\DataHandling\TrustedOperation;
use Formatsoft\ContentReminder\Hooks\DataHandlerHook;
use Formatsoft\ContentReminder\Upgrades\DashboardWidgetHeightUpgradeWizard;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\Widgets\DoughnutChartWidget;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconWidget;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->load('Formatsoft\\ContentReminder\\', '../Classes/*')
        ->exclude([
            '../Classes/Domain/Model/*',
            '../Classes/Exception/*',
            '../Classes/Mail/WeeklyMail.php',
            '../Classes/Mail/WeeklyMailSettings.php',
            '../Classes/StaleContent/StaleContentSettings.php',
            '../Classes/StaleContent/StalePage.php',
            // Registered below, only if EXT:install is available
            '../Classes/Upgrades/*',
            // Widgets need their configuration and are registered below
            '../Classes/Dashboard/Widget/*',
        ]);

    // Instantiated by the DataHandler via GeneralUtility::makeInstance()
    $services->set(DataHandlerHook::class)->public();

    // Shared state between the extension's actions and the DataHandler hook
    $services->set(TrustedOperation::class)->public();

    // Upgrade wizards implement interfaces of EXT:install (still available in TYPO3 v14)
    if (interface_exists(UpgradeWizardInterface::class)) {
        $services->set(DashboardWidgetHeightUpgradeWizard::class);
    }

    // -----------------------------------------------------------------
    // Dashboard widgets (concept 2.4.4), group "contentReminder"
    // -----------------------------------------------------------------
    $ll = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';
    $widget = static fn(string $identifier, string $height = 'medium', string $width = 'medium'): array => [
        'identifier' => $identifier,
        'groupNames' => 'contentReminder',
        'title' => $ll . 'widget.' . $identifier . '.title',
        'description' => $ll . 'widget.' . $identifier . '.description',
        'iconIdentifier' => 'content-reminder-reminder',
        'height' => $height,
        'width' => $width,
    ];

    // List widgets are medium high by default; the lists scroll inside the widget.
    // "Large" variants with more room can be chosen in the widget wizard instead.
    // identifier => [options, height]
    $mine = ['scope' => 'open', 'assignee' => 'me', 'showAssignee' => false, 'emptyLabel' => 'widget.empty.mine'];
    $all = ['scope' => 'open', 'assignee' => 'any', 'showAssignee' => true, 'emptyLabel' => 'widget.empty'];
    $listWidgets = [
        'contentReminderMine' => [$mine, 'medium'],
        'contentReminderMineLarge' => [$mine, 'large'],
        'contentReminderAll' => [$all, 'medium'],
        'contentReminderAllLarge' => [$all, 'large'],
        'contentReminderUnassigned' => [['scope' => 'open', 'assignee' => 'unassigned', 'showAssignee' => false, 'emptyLabel' => 'widget.empty.unassigned'], 'medium'],
        'contentReminderOverdue' => [['scope' => 'overdue', 'assignee' => 'any', 'showAssignee' => true, 'emptyLabel' => 'widget.empty.overdue'], 'medium'],
    ];
    foreach ($listWidgets as $identifier => [$options, $height]) {
        $services->set('content_reminder.widget.' . $identifier, ReminderListWidget::class)
            ->arg('$options', $options + ['limit' => 25])
            ->tag('dashboard.widget', $widget($identifier, $height));
    }

    foreach (['contentReminderRecentlyCompleted' => 'medium', 'contentReminderRecentlyCompletedLarge' => 'large'] as $identifier => $height) {
        $services->set('content_reminder.widget.' . $identifier, RecentlyCompletedWidget::class)
            ->arg('$options', ['limit' => 15])
            ->tag('dashboard.widget', $widget($identifier, $height));
    }

    $services->set('content_reminder.widget.contentReminderDueCount', NumberWithIconWidget::class)
        ->arg('$dataProvider', service(DueForUserCountProvider::class))
        ->arg('$options', [
            'title' => $ll . 'widget.contentReminderDueCount.title',
            'subtitle' => $ll . 'widget.contentReminderDueCount.subtitle',
            'icon' => 'content-reminder-reminder',
        ])
        ->tag('dashboard.widget', $widget('contentReminderDueCount', 'small', 'small'));

    $services->set('content_reminder.widget.contentReminderStatus', DoughnutChartWidget::class)
        ->arg('$dataProvider', service(StatusChartProvider::class))
        ->tag('dashboard.widget', $widget('contentReminderStatus', 'medium', 'small'));
};
