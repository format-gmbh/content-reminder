<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Mail;

use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;

/**
 * Renders and sends a weekly mail (HTML and plain text, layout of the TYPO3 system emails).
 */
class WeeklyMailSender
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_mail.xlf:';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function createEmail(WeeklyMail $mail, WeeklyMailSettings $settings, string $siteBaseUrl): FluidEmail
    {
        $languageService = $this->languageServiceFactory->create($mail->language);
        $subject = sprintf(
            $languageService->sL(self::LL . ($mail->unassignedDigest ? 'subject.unassigned' : 'subject')),
            $mail->siteTitle,
            $mail->count()
        );

        $email = GeneralUtility::makeInstance(FluidEmail::class);
        $email
            ->to(new Address($mail->recipientEmail, $mail->recipientName))
            ->from($this->getSender($settings))
            ->subject($subject)
            ->format(FluidEmail::FORMAT_BOTH)
            ->setTemplate('WeeklyReminder')
            ->assignMultiple([
                'mail' => $mail,
                'language' => $mail->language,
                'headline' => $subject,
                'backendUrl' => $settings->backendUrl,
                'lookaheadDays' => $settings->lookaheadDays,
            ]);

        // Commands have no request; the layout needs one for absolute URLs (logo, site URL)
        $request = $this->createRequest($siteBaseUrl);
        if ($request !== null) {
            $email->setRequest($request);
        }
        return $email;
    }

    public function send(WeeklyMail $mail, WeeklyMailSettings $settings, string $siteBaseUrl): void
    {
        $this->mailer->send($this->createEmail($mail, $settings, $siteBaseUrl));
    }

    private function getSender(WeeklyMailSettings $settings): Address
    {
        if ($settings->fromAddress !== '' && GeneralUtility::validEmail($settings->fromAddress)) {
            return new Address($settings->fromAddress, $settings->fromName);
        }
        // Default sender of the installation (MAIL.defaultMailFromAddress, or no-reply@<host>)
        return new Address(
            MailUtility::getSystemFromAddress(),
            $settings->fromName !== '' ? $settings->fromName : (string)MailUtility::getSystemFromName()
        );
    }

    private function createRequest(string $siteBaseUrl): ?ServerRequest
    {
        $uri = new Uri($siteBaseUrl);
        if ($uri->getHost() === '') {
            return null;
        }
        // The current script of a command is vendor/bin/typo3; pretend the frontend entry
        // point, so that siteUrl/sitePath are calculated like in a web request
        $publicPath = Environment::getPublicPath();
        $normalizedParams = new NormalizedParams(
            [
                'HTTP_HOST' => $uri->getAuthority(),
                'HTTPS' => $uri->getScheme() === 'http' ? 'off' : 'on',
                'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => $publicPath . '/index.php',
                'REQUEST_URI' => '/',
            ],
            $GLOBALS['TYPO3_CONF_VARS']['SYS'],
            $publicPath . '/index.php',
            $publicPath
        );
        return (new ServerRequest($uri))
            ->withAttribute('normalizedParams', $normalizedParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }
}
